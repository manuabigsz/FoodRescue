const fs = require('node:fs');
const { Connection, Keypair, PublicKey, Transaction, TransactionInstruction, sendAndConfirmTransaction } = require('@solana/web3.js');
const api = process.env.API_BASE_URL || 'http://food-rescue:8080/api/v1';
const rpc = process.env.SOLANA_RPC_URL || 'https://api.devnet.solana.com';
const base = '/workspace/keypar/devnet-e2e';
const actors = JSON.parse(fs.readFileSync(`${base}/actors.local.json`, 'utf8'));
const trade = JSON.parse(fs.readFileSync(`${base}/donation.local.json`, 'utf8'));
const ngo = Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/ngo.json`, 'utf8'))));
const programId = new PublicKey(process.env.PROGRAM_ID);
const mint = new PublicKey(process.env.MINT_ADDRESS);
const buyerToken = new PublicKey(process.env.NGO_TOKEN_ACCOUNT);
async function request(path, method = 'GET', payload) {
  const response = await fetch(`${api}${path}`, { method, headers: { 'content-type': 'application/json', authorization: `Bearer ${actors.ngo.token}` }, body: payload === undefined ? undefined : JSON.stringify(payload) });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(`${method} ${path} ${response.status}: ${JSON.stringify(body)}`);
  return body.data ?? body;
}
async function main() {
  const prepared = await request(`/trades/${trade.trade_id}/blockchain/prepare`, 'POST');
  const find = (name) => prepared.initialize_instruction.accounts.find((item) => item.name === name);
  const key = (name) => new PublicKey(find(name).pubkey);
  const idBytes = Buffer.alloc(8); idBytes.writeBigUInt64LE(BigInt(trade.trade_id));
  const [tradePda] = PublicKey.findProgramAddressSync([Buffer.from('foodrescue_trade'), idBytes], programId);
  const [vault] = PublicKey.findProgramAddressSync([Buffer.from('foodrescue_vault'), idBytes], programId);
  const instruction = new TransactionInstruction({
    programId,
    keys: [
      { pubkey: ngo.publicKey, isSigner: true, isWritable: true },
      { pubkey: tradePda, isSigner: false, isWritable: true },
      { pubkey: vault, isSigner: false, isWritable: true },
      { pubkey: buyerToken, isSigner: false, isWritable: true },
      { pubkey: key('protocol_config'), isSigner: false, isWritable: false },
      { pubkey: mint, isSigner: false, isWritable: false },
      { pubkey: key('system_program'), isSigner: false, isWritable: false },
      { pubkey: key('token_program'), isSigner: false, isWritable: false },
    ],
    data: Buffer.from(prepared.initialize_instruction.data_base64, 'base64'),
  });
  const signature = await sendAndConfirmTransaction(new Connection(rpc, 'confirmed'), new Transaction().add(instruction), [ngo], { commitment: 'confirmed' });
  console.log(`donation_initialize_signature=${signature}`);
  console.log(`donation_trade_pda=${tradePda.toBase58()}`);
  console.log(`donation_vault=${vault.toBase58()}`);
  await request(`/trades/${trade.trade_id}/blockchain/initialize/confirm`, 'POST', { signature, trade_pda: tradePda.toBase58(), vault_token_account: vault.toBase58() });
  fs.writeFileSync(`${base}/donation-blockchain-initialize.local.json`, `${JSON.stringify({ signature, trade_pda: tradePda.toBase58(), vault_token_account: vault.toBase58() }, null, 2)}\n`, { mode: 0o600 });
  console.log('donation_backend_status=confirmed');
}
main().catch((error) => { console.error(error.stack || error.message || error); process.exitCode = 1; });
