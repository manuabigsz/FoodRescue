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
const trade = JSON.parse(fs.readFileSync(`${base}/donation.local.json`, 'utf8'));
const init = JSON.parse(fs.readFileSync(`${base}/donation-blockchain-initialize.local.json`, 'utf8'));
const programId = new PublicKey(process.env.PROGRAM_ID);
const wallets = {
  producer: Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/producer.json`, 'utf8')))),
  carrier: Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/carrier.json`, 'utf8')))),
};
async function request(path, actor, method = 'GET', payload) {
  const response = await fetch(`${api}${path}`, { method, headers: { 'content-type': 'application/json', authorization: `Bearer ${tokenFor(actor)}` }, body: payload === undefined ? undefined : JSON.stringify(payload) });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(`${method} ${path} ${response.status}: ${JSON.stringify(body)}`);
  return body.data ?? body;
}
async function main() {
  const connection = new Connection(rpc, 'confirmed');
  const pda = new PublicKey(init.trade_pda);
  const signatures = {};
  for (const [operation, actor, tag] of [['ready-for-pickup', 'producer', 6], ['pickup', 'carrier', 7], ['delivered', 'carrier', 8]]) {
    const prepared = await request(`/trades/${tradeId}/delivery/${operation}/prepare`, actor, 'POST');
    const ix = new TransactionInstruction({ programId, keys: [{ pubkey: wallets[actor].publicKey, isSigner: true, isWritable: false }, { pubkey: pda, isSigner: false, isWritable: true }], data: Buffer.from(prepared.instruction.data_base64, 'base64') });
    const signature = await sendAndConfirmTransaction(connection, new Transaction().add(ix), [wallets[actor]], { commitment: 'confirmed' });
    await request(`/trades/${tradeId}/delivery/${operation}`, actor, 'POST', { signature });
    signatures[operation] = signature;
    console.log(`donation_${operation}_signature=${signature}`);
  }
  fs.writeFileSync(`${base}/donation-blockchain-delivery.local.json`, `${JSON.stringify({ signatures }, null, 2)}\n`, { mode: 0o600 });
}
main().catch((error) => { console.error(error.stack || error.message || error); process.exitCode = 1; });
