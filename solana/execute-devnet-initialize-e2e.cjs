const fs = require('node:fs');
const {
  Connection,
  Keypair,
  PublicKey,
  Transaction,
  TransactionInstruction,
  sendAndConfirmTransaction,
} = require('@solana/web3.js');

const api = process.env.API_BASE_URL || 'http://food-rescue:8080/api/v1';
const rpc = process.env.SOLANA_RPC_URL || 'https://api.devnet.solana.com';
const base = '/workspace/keypar/devnet-e2e';
const actors = JSON.parse(fs.readFileSync(`${base}/actors.local.json`, 'utf8'));
const trade = JSON.parse(fs.readFileSync(`${base}/trade.local.json`, 'utf8'));
const authority = Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/authority.json`, 'utf8'))));
const buyer = Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/buyer.json`, 'utf8'))));
const programId = new PublicKey(process.env.PROGRAM_ID);
const buyerToken = new PublicKey(process.env.BUYER_TOKEN_ACCOUNT);
const mint = new PublicKey(process.env.MINT_ADDRESS);

async function request(path, method = 'GET', payload) {
  const response = await fetch(`${api}${path}`, {
    method,
    headers: { 'content-type': 'application/json', authorization: `Bearer ${actors.buyer.token}` },
    body: payload === undefined ? undefined : JSON.stringify(payload),
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(`${method} ${path} ${response.status}: ${JSON.stringify(body)}`);
  return body.data ?? body;
}

async function main() {
const prepared = await request(`/trades/${trade.trade_id}/blockchain/prepare`, 'POST');
fs.writeFileSync(`${base}/blockchain-prepare.json`, `${JSON.stringify(prepared, null, 2)}\n`, { mode: 0o600 });

const account = (name) => prepared.initialize_instruction.accounts.find((item) => item.name === name);
const pubkey = (name) => {
  const item = account(name);
  if (!item) throw new Error(`Conta ausente na preparação: ${name}`);
  if (!item.pubkey) throw new Error(`A conta ${name} precisa ser derivada pelo frontend.`);
  return new PublicKey(item.pubkey);
};

const instruction = new TransactionInstruction({
  programId,
  keys: [
    { pubkey: pubkey('buyer'), isSigner: true, isWritable: account('buyer').writable },
    { pubkey: PublicKey.findProgramAddressSync([Buffer.from('foodrescue_trade'), Buffer.from([1, 0, 0, 0, 0, 0, 0, 0])], programId)[0], isSigner: false, isWritable: true },
    { pubkey: PublicKey.findProgramAddressSync([Buffer.from('foodrescue_vault'), Buffer.from([1, 0, 0, 0, 0, 0, 0, 0])], programId)[0], isSigner: false, isWritable: true },
    { pubkey: buyerToken, isSigner: false, isWritable: true },
    { pubkey: pubkey('protocol_config'), isSigner: false, isWritable: false },
    { pubkey: pubkey('protocol_authority'), isSigner: true, isWritable: false },
    { pubkey: mint, isSigner: false, isWritable: false },
    { pubkey: pubkey('system_program'), isSigner: false, isWritable: false },
    { pubkey: pubkey('token_program'), isSigner: false, isWritable: false },
  ],
  data: Buffer.from(prepared.initialize_instruction.data_base64, 'base64'),
});

const connection = new Connection(rpc, 'confirmed');
const signature = await sendAndConfirmTransaction(connection, new Transaction().add(instruction), [buyer, authority], { commitment: 'confirmed' });
const tradePda = instruction.keys[1].pubkey.toBase58();
const vault = instruction.keys[2].pubkey.toBase58();
console.log(`initialize_signature=${signature}`);
console.log(`trade_pda=${tradePda}`);
console.log(`vault_token_account=${vault}`);

const confirmed = await request(`/trades/${trade.trade_id}/blockchain/initialize/confirm`, 'POST', {
  signature,
  trade_pda: tradePda,
  vault_token_account: vault,
});
console.log(`backend_status=${confirmed.status || confirmed.trade_status || 'confirmed'}`);
fs.writeFileSync(`${base}/blockchain-initialize.local.json`, `${JSON.stringify({ signature, trade_pda: tradePda, vault_token_account: vault, confirmed }, null, 2)}\n`, { mode: 0o600 });
}

main().catch((error) => {
  console.error(error.stack || error.message || error);
  process.exitCode = 1;
});
