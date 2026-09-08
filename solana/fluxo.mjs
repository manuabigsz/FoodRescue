/**
 * Executa as etapas on-chain de uma operação sem exigir variáveis de ambiente.
 * Faz login sozinho, descobre qual operação está no ponto certo e chama os
 * scripts existentes com o ambiente já montado.
 *
 *   npm run pagar                 → initialize + funding da operação pendente
 *   npm run entregar              → coleta e entrega
 *   npm run liquidar              → settlement
 *   npm run pagar -- --trade 12   → força uma operação específica
 *   npm run pagar -- --api http://localhost:8081/api/v1
 */
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const raiz = path.dirname(fileURLToPath(import.meta.url));
const config = JSON.parse(fs.readFileSync(path.join(raiz, 'devnet.config.json'), 'utf8'));

const argv = process.argv.slice(2);
const etapa = argv[0];
const opcao = (nome) => {
    const i = argv.indexOf('--' + nome);

    return i >= 0 ? argv[i + 1] : undefined;
};

const api = opcao('api') || process.env.API_BASE_URL || config.apiBaseUrl;

/** Estados em que cada etapa pode agir, na ordem do ciclo. */
const ETAPAS = {
    pagar: { estados: ['waiting_payment'], scripts: ['execute-devnet-initialize-e2e.cjs', 'execute-devnet-funding-e2e.cjs'] },
    entregar: { estados: ['funded', 'ready_for_pickup', 'in_transit'], scripts: ['execute-devnet-delivery-e2e.cjs'] },
    liquidar: { estados: ['delivered'], scripts: ['execute-devnet-settlement-e2e.cjs'] },
};

if (!ETAPAS[etapa]) {
    console.error('Etapa desconhecida. Use: pagar, entregar ou liquidar.');
    process.exit(1);
}

async function login(email) {
    const resposta = await fetch(`${api}/auth/login`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', accept: 'application/json' },
        body: JSON.stringify({ email, password: config.contas.senha, device_name: 'fluxo' }),
    });
    const corpo = await resposta.json().catch(() => ({}));
    if (!corpo.data?.token) {
        throw new Error(`Login de ${email} falhou (${resposta.status}): ${corpo.message || 'resposta inesperada'}. API: ${api}`);
    }

    return corpo.data.token;
}

async function operacoes(token) {
    const resposta = await fetch(`${api}/trades?per_page=50`, {
        headers: { accept: 'application/json', authorization: `Bearer ${token}` },
    });
    const corpo = await resposta.json().catch(() => ({}));
    if (!resposta.ok) throw new Error(`Não consegui listar as operações (${resposta.status}).`);

    return corpo.data || [];
}

function executar(script, env) {
    return new Promise((resolve, reject) => {
        console.log(`\n▸ ${script}`);
        const filho = spawn(process.execPath, [path.join(raiz, script)], { env: { ...process.env, ...env }, stdio: 'inherit' });
        filho.on('close', (codigo) => (codigo === 0 ? resolve() : reject(new Error(`${script} terminou com código ${codigo}`))));
    });
}

const { estados, scripts } = ETAPAS[etapa];
const tokenComprador = await login(config.contas.comprador);
const lista = await operacoes(tokenComprador);
const forcado = opcao('trade');

const alvo = forcado
    ? lista.find((t) => String(t.id) === String(forcado))
    : lista.find((t) => estados.includes(t.status));

if (!alvo) {
    console.error(`Nenhuma operação ${forcado ? '#' + forcado : 'no estado ' + estados.join(' ou ')} foi encontrada para ${config.contas.comprador}.`);
    console.error('Operações disponíveis:');
    lista.forEach((t) => console.error(`  #${t.id} — ${t.status}`));
    process.exit(1);
}

if (!estados.includes(alvo.status)) {
    console.error(`A operação #${alvo.id} está em "${alvo.status}"; a etapa "${etapa}" atua em: ${estados.join(', ')}.`);
    process.exit(1);
}

console.log(`Operação #${alvo.id} · estado ${alvo.status} · total ${alvo.buyer_total} FRUSD`);

/** Transporte pelo próprio destinatário não tem transportadora para assinar a coleta. */
const atorEntrega = alvo.shipping_request?.status === 'buyer_managed' ? 'buyer' : 'carrier';

const ambiente = {
    KEYPAIR_DIR: path.join(raiz, config.keypairDir),
    API_BASE_URL: api,
    SOLANA_RPC_URL: config.rpcUrl,
    PROGRAM_ID: config.programId,
    MINT_ADDRESS: config.mint,
    BUYER_TOKEN_ACCOUNT: config.tokenAccounts.buyer,
    PRODUCER_TOKEN_ACCOUNT: config.tokenAccounts.producer,
    CARRIER_TOKEN_ACCOUNT: config.tokenAccounts.carrier,
    TREASURY_TOKEN_ACCOUNT: config.tokenAccounts.treasury,
    TRADE_ID: String(alvo.id),
    API_TOKEN: tokenComprador,
    API_TOKEN_BUYER: tokenComprador,
    API_TOKEN_PRODUCER: await login(config.contas.produtor),
    API_TOKEN_CARRIER: await login(config.contas.transportadora),
    DELIVERY_ACTOR: atorEntrega,
};

for (const script of scripts) {
    await executar(script, ambiente);
}

const conferencia = await fetch(`${api}/trades/${alvo.id}`, {
    headers: { accept: 'application/json', authorization: `Bearer ${tokenComprador}` },
});
const atual = (await conferencia.json().catch(() => ({}))).data;
console.log(`\n✓ Operação #${alvo.id} agora está em "${atual?.status ?? 'desconhecido'}".`);
