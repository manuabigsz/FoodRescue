/**
 * Assinatura das transações do programa FoodRescue pela carteira do navegador.
 *
 * O backend nunca guarda chave privada: ele apenas prepara a instrução (contas,
 * ordem e `data`) e depois confere a assinatura on-chain. Quem assina é o ator,
 * na própria carteira. Este módulo é a ponte entre os dois lados — monta a
 * transação exatamente como a preparação descreve, pede a assinatura e devolve
 * a assinatura que o endpoint de confirmação espera.
 *
 * A lista de contas vem sempre da preparação, nunca de uma cópia fixa aqui:
 * quando o programa passar a exigir uma conta nova, a tela acompanha sozinha.
 */

const ASSOCIATED_TOKEN_PROGRAM = 'ATokenGPvbdGVxr1b2hvZbsiqW5xWH25efTNsLJA8knL';
const SYSTEM_PROGRAM = '11111111111111111111111111111111';
const TOKEN_PROGRAM = 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA';

/** Discriminador de CreateIdempotent no Associated Token Program. */
const ATA_CREATE_IDEMPOTENT = 1;

/** Contas marcadas como `derived` na preparação e a semente que as gera. */
const PDA_SEED_BY_ACCOUNT = {
    trade_pda: 'trade',
    vault_token_account: 'vault',
    rescue_proof_pda: 'rescue',
};

let web3Module = null;

/** O web3.js é grande e só faz falta na hora de assinar; carrega sob demanda. */
async function web3() {
    if (!web3Module) web3Module = await import('@solana/web3.js');

    return web3Module;
}

export function walletProvider() {
    const provider = window.solana;
    if (!provider || typeof provider.connect !== 'function') {
        throw new Error('Nenhuma carteira Solana compatível foi encontrada no navegador. Instale a Phantom para assinar.');
    }

    return provider;
}

export function walletAvailable() {
    return Boolean(window.solana && typeof window.solana.connect === 'function');
}

/** Deriva o PDA administrativo do protocolo a partir da authority conectada. */
export async function protocolConfigAddress(programId, authority) {
    const { PublicKey } = await web3();

    return PublicKey.findProgramAddressSync(
        [new TextEncoder().encode('foodrescue_protocol'), new PublicKey(authority).toBytes()],
        new PublicKey(programId),
    )[0].toBase58();
}

/**
 * Devolve o endereço conectado. `onlyIfTrusted` reaproveita uma autorização
 * anterior sem abrir o popup; se não houver, pede a conexão.
 */
export async function connectedWallet() {
    const provider = walletProvider();
    if (provider.isConnected && provider.publicKey) return provider.publicKey.toString();

    const result = await provider.connect();

    return (result?.publicKey || provider.publicKey).toString();
}

/**
 * A carteira conectada precisa ser a mesma que o backend registrou para o ator,
 * senão o programa recusaria a transação depois de o usuário já ter assinado.
 */
export async function connectedWalletFor(expected, papel) {
    const wallet = await connectedWallet();
    if (expected && wallet !== expected) {
        throw new Error('A carteira conectada não é a cadastrada como ' + papel + '. Troque para ' + shortAddress(expected) + ' na extensão e tente de novo.');
    }

    return wallet;
}

function shortAddress(address) {
    return address.length > 16 ? address.slice(0, 6) + '…' + address.slice(-6) : address;
}

export function decodeBase64(value) {
    const binary = atob(value);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);

    return bytes;
}

/**
 * Cada semente chega autodescrita — `{ type, value }` — para o front não
 * replicar a regra de codificação do backend. Tipo desconhecido é erro, não
 * palpite: derivar o PDA errado levaria a assinar uma transação inválida.
 */
async function seedBytes(seed) {
    const { PublicKey } = await web3();
    if (seed?.type === 'utf8') return new TextEncoder().encode(seed.value);
    if (seed?.type === 'base64') return decodeBase64(seed.value);
    if (seed?.type === 'pubkey') return new PublicKey(seed.value).toBytes();

    throw new Error('Tipo de semente não suportado na preparação: ' + JSON.stringify(seed?.type) + '.');
}

export async function derivedAddress(preparation, name) {
    const { PublicKey } = await web3();
    const key = PDA_SEED_BY_ACCOUNT[name];
    const seeds = key ? preparation.pda_seeds?.[key] : null;
    if (!Array.isArray(seeds) || !seeds.length) {
        throw new Error('A preparação não trouxe as sementes da conta ' + name + '.');
    }

    const bytes = [];
    for (const seed of seeds) bytes.push(await seedBytes(seed));

    return PublicKey.findProgramAddressSync(bytes, new PublicKey(preparation.program_id))[0];
}

export async function associatedTokenAddress(owner, mint, tokenProgram) {
    const { PublicKey } = await web3();

    return PublicKey.findProgramAddressSync(
        [new PublicKey(owner).toBytes(), new PublicKey(tokenProgram).toBytes(), new PublicKey(mint).toBytes()],
        new PublicKey(ASSOCIATED_TOKEN_PROGRAM),
    )[0];
}

/** `producer_token_account` pertence à carteira em `wallets.producer`, e assim por diante. */
function tokenAccountOwner(preparation, name) {
    const papel = name.replace(/_token_account$/, '');

    return preparation.wallets?.[papel] || null;
}

async function resolveAccount(preparation, entry) {
    const { PublicKey } = await web3();
    if (entry.pubkey) return { pubkey: new PublicKey(entry.pubkey) };
    if (entry.derived) return { pubkey: await derivedAddress(preparation, entry.name) };
    if (entry.derived_by_frontend) {
        const owner = entry.owner_wallet || tokenAccountOwner(preparation, entry.name);
        const mint = entry.mint || preparation.mint;
        if (!owner) throw new Error('A preparação não informou a carteira dona da conta ' + entry.name + '.');
        if (!mint) throw new Error('A preparação não informou a mint da conta ' + entry.name + '.');
        const tokenProgram = preparation.token_program_id || TOKEN_PROGRAM;

        return {
            pubkey: await associatedTokenAddress(owner, mint, tokenProgram),
            ata: { owner: owner, mint: mint, tokenProgram: tokenProgram },
        };
    }

    throw new Error('A preparação não trouxe como resolver a conta ' + entry.name + '.');
}

/** Traduz a lista de contas da preparação para o formato do web3.js. */
export async function resolveAccounts(preparation, instruction) {
    const keys = [];
    for (const entry of instruction.accounts) {
        const resolved = await resolveAccount(preparation, entry);
        keys.push({
            name: entry.name,
            pubkey: resolved.pubkey,
            ata: resolved.ata || null,
            isSigner: Boolean(entry.signer),
            isWritable: Boolean(entry.writable),
        });
    }

    return keys;
}

/**
 * Assinaturas que a carteira conectada não consegue dar. Uma transação com mais
 * de um signatário — a contraparte no cancelamento com escrow financiado, ou a
 * authority no Proof of Rescue — não sai só pela Phantom deste usuário, então a
 * tela precisa dizer isso antes de o usuário clicar.
 */
export function missingSigners(keys, wallet) {
    return keys
        .filter(function (key) { return key.isSigner && key.pubkey.toBase58() !== wallet; })
        .map(function (key) { return { name: key.name, pubkey: key.pubkey.toBase58() }; });
}

export async function pendingCoSigners(preparation, instruction, wallet) {
    return missingSigners(await resolveAccounts(preparation, instruction), wallet);
}

async function connectionFor(preparation) {
    const { Connection } = await web3();

    return new Connection(preparation.rpc_url, preparation.commitment || 'confirmed');
}

/**
 * Instrução que abre a conta de token associada de uma carteira. É idempotente:
 * se a conta já existir quando a transação executar, ela não falha.
 */
export async function createTokenAccountInstruction(payer, key) {
    const { PublicKey, TransactionInstruction } = await web3();

    return new TransactionInstruction({
        programId: new PublicKey(ASSOCIATED_TOKEN_PROGRAM),
        keys: [
            { pubkey: new PublicKey(payer), isSigner: true, isWritable: true },
            { pubkey: key.pubkey, isSigner: false, isWritable: true },
            { pubkey: new PublicKey(key.ata.owner), isSigner: false, isWritable: false },
            { pubkey: new PublicKey(key.ata.mint), isSigner: false, isWritable: false },
            { pubkey: new PublicKey(SYSTEM_PROGRAM), isSigner: false, isWritable: false },
            { pubkey: new PublicKey(key.ata.tokenProgram), isSigner: false, isWritable: false },
        ],
        data: new Uint8Array([ATA_CREATE_IDEMPOTENT]),
    });
}

/**
 * O programa transfere para as contas de token do produtor, da tesouraria e da
 * transportadora, mas não as cria — e uma conta inexistente derrubaria a
 * liquidação no meio da CPI, com erro opaco. Quem assina abre as que faltarem
 * na mesma transação: se algo falhar depois, a criação volta atrás junto.
 */
export async function tokenAccountCreations(preparation, keys, payer) {
    const alvos = keys.filter(function (key) { return key.ata; });
    if (!alvos.length) return [];

    const connection = await connectionFor(preparation);
    const infos = await connection.getMultipleAccountsInfo(alvos.map(function (key) { return key.pubkey; }));
    const faltantes = alvos.filter(function (_key, index) { return infos[index] === null; });

    const instructions = [];
    for (const key of faltantes) instructions.push(await createTokenAccountInstruction(payer, key));

    return instructions;
}

/**
 * Monta, assina na carteira e envia a instrução preparada. Devolve a assinatura
 * já confirmada, que é o que o endpoint de confirmação do backend exige.
 */
export async function signAndSend(preparation, instruction, wallet) {
    const provider = walletProvider();
    const { PublicKey, Transaction, TransactionInstruction } = await web3();
    const keys = await resolveAccounts(preparation, instruction);

    const faltantes = missingSigners(keys, wallet);
    if (faltantes.length) {
        throw new Error('Esta transação também precisa da assinatura de ' + faltantes.map(function (item) {
            return item.name + ' (' + shortAddress(item.pubkey) + ')';
        }).join(', ') + '. A carteira conectada sozinha não consegue enviá-la.');
    }

    const connection = await connectionFor(preparation);
    const commitment = preparation.commitment || 'confirmed';
    const transaction = new Transaction();
    for (const criacao of await tokenAccountCreations(preparation, keys, wallet)) transaction.add(criacao);
    transaction.add(new TransactionInstruction({
        programId: new PublicKey(preparation.program_id),
        keys: keys.map(function (key) { return { pubkey: key.pubkey, isSigner: key.isSigner, isWritable: key.isWritable }; }),
        data: decodeBase64(instruction.data_base64),
    }));

    const { blockhash, lastValidBlockHeight } = await connection.getLatestBlockhash(commitment);
    transaction.recentBlockhash = blockhash;
    transaction.feePayer = new PublicKey(wallet);

    const signature = await sendThroughWallet(provider, connection, transaction);
    await connection.confirmTransaction({ signature, blockhash, lastValidBlockHeight }, commitment);

    return signature;
}

/**
 * A Phantom assina e envia num passo só; carteiras que não expõem
 * `signAndSendTransaction` ainda funcionam pelo caminho manual.
 */
async function sendThroughWallet(provider, connection, transaction) {
    if (typeof provider.signAndSendTransaction === 'function') {
        const result = await provider.signAndSendTransaction(transaction);

        return typeof result === 'string' ? result : result.signature;
    }

    if (typeof provider.signTransaction !== 'function') {
        throw new Error('A carteira conectada não sabe assinar transações, apenas mensagens.');
    }

    const signed = await provider.signTransaction(transaction);

    return connection.sendRawTransaction(signed.serialize());
}

/**
 * A carteira devolve mensagens técnicas; aqui viram texto que o usuário entende,
 * preservando o original quando não é um caso conhecido.
 */
export function walletErrorMessage(error) {
    const details = [
        error?.message,
        error?.data?.message,
        error?.error?.message,
        Array.isArray(error?.logs) ? error.logs.join(' ') : null,
    ].filter(Boolean).join(' ');
    const message = details || 'Não foi possível assinar a transação.';
    if (error?.code === 4001 || /user rejected|rejected the request/i.test(message)) {
        return 'Assinatura cancelada na carteira.';
    }
    if (/insufficient|not enough funds|insufficient lamports|attempt to debit an account|custom program error:\s*0x1\b|0x1\b/i.test(message)) {
        return 'Saldo insuficiente na carteira para esta transação. Verifique o SOL das taxas e o saldo em FRUSD.';
    }

    // Algumas extensões escondem o motivo real e retornam somente “Unexpected
    // error”. Ainda assim, a orientação deve ajudar o usuário a corrigir os
    // pré-requisitos mais comuns sem expor a mensagem técnica da carteira.
    if (/unexpected error|transaction failed|simulation failed|failed to send/i.test(message)) {
        return 'Não foi possível concluir a transação. Confira se sua carteira tem SOL para as taxas e FRUSD suficiente para o pagamento e tente novamente.';
    }

    return message;
}
