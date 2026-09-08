const fs = require('node:fs');
const { Connection, Keypair, PublicKey, Transaction, TransactionInstruction, sendAndConfirmTransaction } = require('@solana/web3.js');

const api = process.env.API_BASE_URL || 'http://food-rescue:8080/api/v1';
const rpc = process.env.SOLANA_RPC_URL || 'https://api.devnet.solana.com';
const base = process.env.KEYPAIR_DIR || '/workspace/keypar/devnet-e2e';
const actors = JSON.parse(fs.readFileSync(`${base}/actors.local.json`, 'utf8'));

/** Token da API por ator: o ambiente tem prioridade sobre o actors.local.json,
 *  que guarda sessões de outra execução e pode estar vencido. */
const tokenFor = (name) => process.env[`API_TOKEN_${String(name).toUpperCase()}`] || actors[name]?.token;


/** O trade alvo vem do ambiente quando a operação foi criada pela interface. */
const tradeId = Number(process.env.TRADE_ID || trade.trade_id);
if (!Number.isInteger(tradeId) || tradeId <= 0) {
  throw new Error('Defina TRADE_ID com o número da operação (ex.: 12). Valor recebido: ' + JSON.stringify(process.env.TRADE_ID));
}


function tradeIdSeed() {
  const seed = Buffer.alloc(8);
  seed.writeBigUInt64LE(BigInt(tradeId));

  return seed;
}
const trade = JSON.parse(fs.readFileSync(`${base}/trade.local.json`, 'utf8'));
const init = JSON.parse(fs.readFileSync(`${base}/blockchain-initialize.local.json`, 'utf8'));
const programId = new PublicKey(process.env.PROGRAM_ID);
const tradePda = new PublicKey(init.trade_pda);
const wallets = {
  producer: Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/producer.json`, 'utf8')))),
  carrier: Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/carrier.json`, 'utf8')))),
  buyer: Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/buyer.json`, 'utf8')))),
  ngo: Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/ngo.json`, 'utf8')))),
};

async function request(path, token, method = 'GET', payload) {
  const response = await fetch(`${api}${path}`, { method, headers: { 'content-type': 'application/json', authorization: `Bearer ${token}` }, body: payload === undefined ? undefined : JSON.stringify(payload) });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(`${method} ${path} ${response.status}: ${JSON.stringify(body)}`);
  return body.data ?? body;
}

async function main() {
  const connection = new Connection(rpc, 'confirmed');
  /**
   * Quem coleta e quem recebe depende da logística: com transportadora
   * contratada é o carrier; em transporte pelo próprio destinatário é o buyer.
   * DELIVERY_ACTOR permite escolher sem editar o script.
   */
  const deliveryActor = process.env.DELIVERY_ACTOR || 'carrier';
  const operations = [
    ['ready-for-pickup', 'producer', 6],
    ['pickup', deliveryActor, 7],
    ['delivered', deliveryActor, 8],
  ];
  const signatures = {};

  /** Retomada: etapas já concluídas são puladas em vez de falharem com 409. */
  const atual = await request(`/trades/${tradeId}`, tokenFor('producer'));
  const jaFeitas = { ready_for_pickup: ['ready-for-pickup'], in_transit: ['ready-for-pickup', 'pickup'], delivered: ['ready-for-pickup', 'pickup', 'delivered'], proof_pending: ['ready-for-pickup', 'pickup', 'delivered'], completed: ['ready-for-pickup', 'pickup', 'delivered'] };
  const pular = jaFeitas[atual.status] || [];

  for (const [operation, actorName, tag] of operations) {
    if (pular.includes(operation)) {
      console.log(`${operation}=já concluído`);
      continue;
    }
    const token = tokenFor(actorName);
    const prepared = await request(`/trades/${tradeId}/delivery/${operation}/prepare`, token, 'POST');
    const instruction = new TransactionInstruction({
      programId,
      keys: [
        { pubkey: wallets[actorName].publicKey, isSigner: true, isWritable: false },
        { pubkey: tradePda, isSigner: false, isWritable: true },
      ],
      data: Buffer.from(prepared.instruction.data_base64, 'base64'),
    });
    const signature = await sendAndConfirmTransaction(connection, new Transaction().add(instruction), [wallets[actorName]], { commitment: 'confirmed' });
    await request(`/trades/${tradeId}/delivery/${operation}`, token, 'POST', { signature });
    signatures[operation] = signature;
    console.log(`${operation}_signature=${signature}`);
  }
  fs.writeFileSync(`${base}/blockchain-delivery.local.json`, `${JSON.stringify({ signatures }, null, 2)}\n`, { mode: 0o600 });
}

main().catch((error) => { console.error(error.stack || error.message || error); process.exitCode = 1; });
