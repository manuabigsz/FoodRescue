const fs = require('node:fs');
const { Connection, Keypair, PublicKey, Transaction, TransactionInstruction, sendAndConfirmTransaction } = require('@solana/web3.js');

const api = process.env.API_BASE_URL || 'http://food-rescue:8080/api/v1';
const rpc = process.env.SOLANA_RPC_URL || 'https://api.devnet.solana.com';
const base = '/workspace/keypar/devnet-e2e';
const actors = JSON.parse(fs.readFileSync(`${base}/actors.local.json`, 'utf8'));
const trade = JSON.parse(fs.readFileSync(`${base}/trade.local.json`, 'utf8'));
const init = JSON.parse(fs.readFileSync(`${base}/blockchain-initialize.local.json`, 'utf8'));
const buyer = Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/buyer.json`, 'utf8'))));

async function request(path, method = 'GET', payload) {
  const response = await fetch(`${api}${path}`, { method, headers: { 'content-type': 'application/json', authorization: `Bearer ${actors.buyer.token}` }, body: payload === undefined ? undefined : JSON.stringify(payload) });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(`${method} ${path} ${response.status}: ${JSON.stringify(body)}`);
  return body.data ?? body;
}

async function main() {
  const prepared = await request(`/trades/${trade.trade_id}/blockchain/prepare`, 'POST');
  const byName = (name) => prepared.fund_instruction.accounts.find((item) => item.name === name);
  const known = { buyer: buyer.publicKey, trade_pda: new PublicKey(init.trade_pda), buyer_token_account: new PublicKey(process.env.BUYER_TOKEN_ACCOUNT), vault_token_account: new PublicKey(init.vault_token_account), mint: new PublicKey(process.env.MINT_ADDRESS), token_program: new PublicKey('TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA') };
  const keys = ['buyer', 'trade_pda', 'buyer_token_account', 'vault_token_account', 'mint', 'token_program'].map((name) => ({ pubkey: known[name] || new PublicKey(byName(name).pubkey), isSigner: byName(name).signer, isWritable: byName(name).writable }));
  const instruction = new TransactionInstruction({ programId: new PublicKey(process.env.PROGRAM_ID), keys, data: Buffer.from(prepared.fund_instruction.data_base64, 'base64') });
  const signature = await sendAndConfirmTransaction(new Connection(rpc, 'confirmed'), new Transaction().add(instruction), [buyer], { commitment: 'confirmed' });
  console.log(`fund_signature=${signature}`);
  const confirmed = await request(`/trades/${trade.trade_id}/blockchain/funding/confirm`, 'POST', { signature });
  console.log(`backend_status=${confirmed.status || confirmed.trade_status || 'confirmed'}`);
  fs.writeFileSync(`${base}/blockchain-funding.local.json`, `${JSON.stringify({ signature, confirmed }, null, 2)}\n`, { mode: 0o600 });
}

main().catch((error) => { console.error(error.stack || error.message || error); process.exitCode = 1; });
