const fs = require('node:fs');
const { Connection, Keypair, PublicKey, Transaction, TransactionInstruction, sendAndConfirmTransaction } = require('@solana/web3.js');
const api = process.env.API_BASE_URL || 'http://food-rescue:8080/api/v1';
const rpc = process.env.SOLANA_RPC_URL || 'https://api.devnet.solana.com';
const base = process.env.KEYPAIR_DIR || '/workspace/keypar/devnet-e2e';
const actors = JSON.parse(fs.readFileSync(`${base}/actors.local.json`, 'utf8'));
const trade = JSON.parse(fs.readFileSync(`${base}/donation.local.json`, 'utf8'));
const ngo = Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/ngo.json`, 'utf8'))));

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
const producer = Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/producer.json`, 'utf8'))));
const authority = Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/authority.json`, 'utf8'))));
const programId = new PublicKey(process.env.PROGRAM_ID);
async function request(path, method = 'GET', payload) {
  const response = await fetch(`${api}${path}`, { method, headers: { 'content-type': 'application/json', authorization: `Bearer ${process.env.API_TOKEN || actors.ngo.token}` }, body: payload === undefined ? undefined : JSON.stringify(payload) });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(`${method} ${path} ${response.status}: ${JSON.stringify(body)}`);
  return body.data ?? body;
}
async function main() {
  const prepared = await request(`/trades/${tradeId}/rescue-proof/prepare`, 'POST');
  const idBytes = Buffer.alloc(8); idBytes.writeBigUInt64LE(BigInt(trade.trade_id));
  const [proofPda] = PublicKey.findProgramAddressSync([Buffer.from('foodrescue_rescue'), idBytes], programId);
  const account = (name) => new PublicKey(prepared.instruction.accounts.find((item) => item.name === name).pubkey);
  const ix = new TransactionInstruction({
    programId,
    keys: [
      { pubkey: ngo.publicKey, isSigner: true, isWritable: true },
      { pubkey: producer.publicKey, isSigner: true, isWritable: false },
      { pubkey: authority.publicKey, isSigner: true, isWritable: false },
      { pubkey: account('protocol_config'), isSigner: false, isWritable: false },
      { pubkey: proofPda, isSigner: false, isWritable: true },
      { pubkey: account('system_program'), isSigner: false, isWritable: false },
    ],
    data: Buffer.from(prepared.instruction.data_base64, 'base64'),
  });
  const signature = await sendAndConfirmTransaction(new Connection(rpc, 'confirmed'), new Transaction().add(ix), [ngo, producer, authority], { commitment: 'confirmed' });
  console.log(`rescue_proof_signature=${signature}`);
  console.log(`rescue_proof_pda=${proofPda.toBase58()}`);
  const confirmed = await request(`/trades/${tradeId}/rescue-proof/confirm`, 'POST', { signature, proof_pda: proofPda.toBase58() });
  console.log(`rescue_backend_status=${confirmed.status || 'completed'}`);
  fs.writeFileSync(`${base}/donation-rescue-proof.local.json`, `${JSON.stringify({ signature, proof_pda: proofPda.toBase58(), metadata_hash: prepared.metadata_hash, confirmed }, null, 2)}\n`, { mode: 0o600 });
}
main().catch((error) => { console.error(error.stack || error.message || error); process.exitCode = 1; });
