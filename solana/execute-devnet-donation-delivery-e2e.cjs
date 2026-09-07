const fs = require('node:fs');
const { Connection, Keypair, PublicKey, Transaction, TransactionInstruction, sendAndConfirmTransaction } = require('@solana/web3.js');
const api = process.env.API_BASE_URL || 'http://food-rescue:8080/api/v1';
const rpc = process.env.SOLANA_RPC_URL || 'https://api.devnet.solana.com';
const base = '/workspace/keypar/devnet-e2e';
const actors = JSON.parse(fs.readFileSync(`${base}/actors.local.json`, 'utf8'));
const trade = JSON.parse(fs.readFileSync(`${base}/donation.local.json`, 'utf8'));
const init = JSON.parse(fs.readFileSync(`${base}/donation-blockchain-initialize.local.json`, 'utf8'));
const programId = new PublicKey(process.env.PROGRAM_ID);
const wallets = {
  producer: Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/producer.json`, 'utf8')))),
  carrier: Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/carrier.json`, 'utf8')))),
};
async function request(path, actor, method = 'GET', payload) {
  const response = await fetch(`${api}${path}`, { method, headers: { 'content-type': 'application/json', authorization: `Bearer ${actors[actor].token}` }, body: payload === undefined ? undefined : JSON.stringify(payload) });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(`${method} ${path} ${response.status}: ${JSON.stringify(body)}`);
  return body.data ?? body;
}
async function main() {
  const connection = new Connection(rpc, 'confirmed');
  const pda = new PublicKey(init.trade_pda);
  const signatures = {};
  for (const [operation, actor, tag] of [['ready-for-pickup', 'producer', 6], ['pickup', 'carrier', 7], ['delivered', 'carrier', 8]]) {
    const prepared = await request(`/trades/${trade.trade_id}/delivery/${operation}/prepare`, actor, 'POST');
    const ix = new TransactionInstruction({ programId, keys: [{ pubkey: wallets[actor].publicKey, isSigner: true, isWritable: false }, { pubkey: pda, isSigner: false, isWritable: true }], data: Buffer.from(prepared.instruction.data_base64, 'base64') });
    const signature = await sendAndConfirmTransaction(connection, new Transaction().add(ix), [wallets[actor]], { commitment: 'confirmed' });
    await request(`/trades/${trade.trade_id}/delivery/${operation}`, actor, 'POST', { signature });
    signatures[operation] = signature;
    console.log(`donation_${operation}_signature=${signature}`);
  }
  fs.writeFileSync(`${base}/donation-blockchain-delivery.local.json`, `${JSON.stringify({ signatures }, null, 2)}\n`, { mode: 0o600 });
}
main().catch((error) => { console.error(error.stack || error.message || error); process.exitCode = 1; });
