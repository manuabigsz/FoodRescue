const fs = require('node:fs');
const { Connection, Keypair, PublicKey, Transaction, TransactionInstruction, sendAndConfirmTransaction } = require('@solana/web3.js');

const api = process.env.API_BASE_URL || 'http://food-rescue:8080/api/v1';
const rpc = process.env.SOLANA_RPC_URL || 'https://api.devnet.solana.com';
const base = '/workspace/keypar/devnet-e2e';
const actors = JSON.parse(fs.readFileSync(`${base}/actors.local.json`, 'utf8'));
const trade = JSON.parse(fs.readFileSync(`${base}/trade.local.json`, 'utf8'));
const init = JSON.parse(fs.readFileSync(`${base}/blockchain-initialize.local.json`, 'utf8'));
const buyer = Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/buyer.json`, 'utf8'))));
const programId = new PublicKey(process.env.PROGRAM_ID);
const tokenProgram = new PublicKey('TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA');

async function request(path, method = 'GET', payload) {
  const response = await fetch(`${api}${path}`, { method, headers: { 'content-type': 'application/json', authorization: `Bearer ${actors.buyer.token}` }, body: payload === undefined ? undefined : JSON.stringify(payload) });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(`${method} ${path} ${response.status}: ${JSON.stringify(body)}`);
  return body.data ?? body;
}

async function main() {
  const prepared = await request(`/trades/${trade.trade_id}/blockchain/settlement/prepare`, 'POST');
  const accounts = prepared.settle_instruction.accounts;
  const known = {
    buyer: buyer.publicKey,
    trade_pda: new PublicKey(init.trade_pda),
    vault_token_account: new PublicKey(init.vault_token_account),
    buyer_token_account: new PublicKey(process.env.BUYER_TOKEN_ACCOUNT),
    producer_token_account: new PublicKey(process.env.PRODUCER_TOKEN_ACCOUNT),
    treasury_token_account: new PublicKey(process.env.TREASURY_TOKEN_ACCOUNT),
    mint: new PublicKey(process.env.MINT_ADDRESS),
    token_program: tokenProgram,
    carrier_token_account: new PublicKey(process.env.CARRIER_TOKEN_ACCOUNT),
  };
  const keys = accounts.map((item) => ({ pubkey: known[item.name] || new PublicKey(item.pubkey), isSigner: item.signer, isWritable: item.writable }));
  const instruction = new TransactionInstruction({ programId, keys, data: Buffer.from(prepared.settle_instruction.data_base64, 'base64') });
  const signature = await sendAndConfirmTransaction(new Connection(rpc, 'confirmed'), new Transaction().add(instruction), [buyer], { commitment: 'confirmed' });
  console.log(`settlement_signature=${signature}`);
  const confirmed = await request(`/trades/${trade.trade_id}/blockchain/settlement/confirm`, 'POST', { signature });
  console.log(`backend_status=${confirmed.status || confirmed.trade_status || 'confirmed'}`);
  fs.writeFileSync(`${base}/blockchain-settlement.local.json`, `${JSON.stringify({ signature, confirmed }, null, 2)}\n`, { mode: 0o600 });
}

main().catch((error) => { console.error(error.stack || error.message || error); process.exitCode = 1; });
