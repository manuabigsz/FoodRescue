const fs = require('node:fs');
const { Connection, Keypair, PublicKey, Transaction, TransactionInstruction, sendAndConfirmTransaction } = require('@solana/web3.js');
const api = process.env.API_BASE_URL || 'http://food-rescue:8080/api/v1';
const rpc = process.env.SOLANA_RPC_URL || 'https://api.devnet.solana.com';
const base = process.env.KEYPAIR_DIR || '/workspace/keypar/devnet-e2e';
const actors = JSON.parse(fs.readFileSync(`${base}/actors.local.json`, 'utf8'));
const trade = JSON.parse(fs.readFileSync(`${base}/donation.local.json`, 'utf8'));
const init = JSON.parse(fs.readFileSync(`${base}/donation-blockchain-initialize.local.json`, 'utf8'));
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
const tokenProgram = new PublicKey('TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA');
async function request(path, method = 'GET', payload) {
  const response = await fetch(`${api}${path}`, { method, headers: { 'content-type': 'application/json', authorization: `Bearer ${process.env.API_TOKEN || actors.ngo.token}` }, body: payload === undefined ? undefined : JSON.stringify(payload) });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(`${method} ${path} ${response.status}: ${JSON.stringify(body)}`);
  return body.data ?? body;
}
async function main() {
  const prepared = await request(`/trades/${tradeId}/blockchain/settlement/prepare`, 'POST');
  const known = {
    buyer: ngo.publicKey,
    trade_pda: new PublicKey(init.trade_pda),
    vault_token_account: new PublicKey(init.vault_token_account),
    buyer_token_account: new PublicKey(process.env.NGO_TOKEN_ACCOUNT),
    producer_token_account: new PublicKey(process.env.PRODUCER_TOKEN_ACCOUNT),
    treasury_token_account: new PublicKey(process.env.TREASURY_TOKEN_ACCOUNT),
    mint: new PublicKey(process.env.MINT_ADDRESS),
    token_program: tokenProgram,
    carrier_token_account: new PublicKey(process.env.CARRIER_TOKEN_ACCOUNT),
  };
  const ix = new TransactionInstruction({ programId: new PublicKey(process.env.PROGRAM_ID), keys: prepared.settle_instruction.accounts.map((item) => ({ pubkey: known[item.name] || new PublicKey(item.pubkey), isSigner: item.signer, isWritable: item.writable })), data: Buffer.from(prepared.settle_instruction.data_base64, 'base64') });
  const signature = await sendAndConfirmTransaction(new Connection(rpc, 'confirmed'), new Transaction().add(ix), [ngo], { commitment: 'confirmed' });
  console.log(`donation_settlement_signature=${signature}`);
  const confirmed = await request(`/trades/${tradeId}/blockchain/settlement/confirm`, 'POST', { signature });
  console.log(`donation_backend_status=${confirmed.status || confirmed.trade_status}`);
  fs.writeFileSync(`${base}/donation-blockchain-settlement.local.json`, `${JSON.stringify({ signature }, null, 2)}\n`, { mode: 0o600 });
}
main().catch((error) => { console.error(error.stack || error.message || error); process.exitCode = 1; });
