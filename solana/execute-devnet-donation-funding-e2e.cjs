const fs = require('node:fs');
const { Connection, Keypair, PublicKey, Transaction, TransactionInstruction, sendAndConfirmTransaction } = require('@solana/web3.js');
const api = process.env.API_BASE_URL || 'http://food-rescue:8080/api/v1';
const rpc = process.env.SOLANA_RPC_URL || 'https://api.devnet.solana.com';
const base = '/workspace/keypar/devnet-e2e';
const actors = JSON.parse(fs.readFileSync(`${base}/actors.local.json`, 'utf8'));
const trade = JSON.parse(fs.readFileSync(`${base}/donation.local.json`, 'utf8'));
const init = JSON.parse(fs.readFileSync(`${base}/donation-blockchain-initialize.local.json`, 'utf8'));
const ngo = Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/ngo.json`, 'utf8'))));
const tokenAccount = new PublicKey(process.env.NGO_TOKEN_ACCOUNT);
async function request(path, method = 'GET', payload) {
  const response = await fetch(`${api}${path}`, { method, headers: { 'content-type': 'application/json', authorization: `Bearer ${actors.ngo.token}` }, body: payload === undefined ? undefined : JSON.stringify(payload) });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(`${method} ${path} ${response.status}: ${JSON.stringify(body)}`);
  return body.data ?? body;
}
async function main() {
  const prepared = await request(`/trades/${trade.trade_id}/blockchain/prepare`, 'POST');
  const known = { buyer: ngo.publicKey, trade_pda: new PublicKey(init.trade_pda), buyer_token_account: tokenAccount, vault_token_account: new PublicKey(init.vault_token_account), mint: new PublicKey(process.env.MINT_ADDRESS), token_program: new PublicKey('TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA') };
  const instruction = new TransactionInstruction({ programId: new PublicKey(process.env.PROGRAM_ID), keys: prepared.fund_instruction.accounts.map((item) => ({ pubkey: known[item.name] || new PublicKey(item.pubkey), isSigner: item.signer, isWritable: item.writable })), data: Buffer.from(prepared.fund_instruction.data_base64, 'base64') });
  const signature = await sendAndConfirmTransaction(new Connection(rpc, 'confirmed'), new Transaction().add(instruction), [ngo], { commitment: 'confirmed' });
  console.log(`donation_fund_signature=${signature}`);
  await request(`/trades/${trade.trade_id}/blockchain/funding/confirm`, 'POST', { signature });
  fs.writeFileSync(`${base}/donation-blockchain-funding.local.json`, `${JSON.stringify({ signature }, null, 2)}\n`, { mode: 0o600 });
  console.log('donation_backend_status=funded');
}
main().catch((error) => { console.error(error.stack || error.message || error); process.exitCode = 1; });
