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
const base = process.env.KEYPAIR_DIR || '/workspace/keypar/devnet-e2e';
const actors = JSON.parse(fs.readFileSync(`${base}/actors.local.json`, 'utf8'));
const trade = JSON.parse(fs.readFileSync(`${base}/trade.local.json`, 'utf8'));
const buyer = Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/buyer.json`, 'utf8'))));
const authority = Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/authority.json`, 'utf8'))));

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
const programId = new PublicKey(process.env.PROGRAM_ID);
const buyerToken = new PublicKey(process.env.BUYER_TOKEN_ACCOUNT);
const mint = new PublicKey(process.env.MINT_ADDRESS);

async function request(path, method = 'GET', payload) {
  const response = await fetch(`${api}${path}`, {
    method,
    headers: { 'content-type': 'application/json', authorization: `Bearer ${process.env.API_TOKEN || actors.buyer.token}` },
    body: payload === undefined ? undefined : JSON.stringify(payload),
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(`${method} ${path} ${response.status}: ${JSON.stringify(body)}`);
  return body.data ?? body;
}

async function main() {
const prepared = await request(`/trades/${tradeId}/blockchain/prepare`, 'POST');
fs.writeFileSync(`${base}/blockchain-prepare.json`, `${JSON.stringify(prepared, null, 2)}\n`, { mode: 0o600 });

/**
 * A ordem e a quantidade de contas vêm da preparação do backend, não de uma
 * lista fixa aqui: quando o programa passa a exigir um signatário novo — como a
 * protocol_authority —, o script acompanha sem precisar de edição.
 */
const derive = (seed) => PublicKey.findProgramAddressSync([Buffer.from(seed), tradeIdSeed()], programId)[0];
const derived = {
  trade_pda: derive('foodrescue_trade'),
  vault_token_account: derive('foodrescue_vault'),
  buyer_token_account: buyerToken,
};

const keys = prepared.initialize_instruction.accounts.map((item) => {
  const pubkey = item.pubkey ? new PublicKey(item.pubkey) : derived[item.name];
  if (!pubkey) throw new Error(`Não sei derivar a conta ${item.name}; a preparação não trouxe pubkey.`);

  return { pubkey, isSigner: Boolean(item.signer), isWritable: Boolean(item.writable) };
});

const instruction = new TransactionInstruction({
  programId,
  keys,
  data: Buffer.from(prepared.initialize_instruction.data_base64, 'base64'),
});

/** Cada conta marcada como signatária precisa de uma chave local correspondente. */
const wallets = {
  [buyer.publicKey.toBase58()]: buyer,
  [authority.publicKey.toBase58()]: authority,
};
const signers = keys.filter((key) => key.isSigner).map((key) => {
  const wallet = wallets[key.pubkey.toBase58()];
  if (!wallet) throw new Error(`Falta a chave privada do signatário ${key.pubkey.toBase58()}.`);

  return wallet;
});

const connection = new Connection(rpc, 'confirmed');
const signature = await sendAndConfirmTransaction(connection, new Transaction().add(instruction), signers, { commitment: 'confirmed' });
const tradePda = derived.trade_pda.toBase58();
const vault = derived.vault_token_account.toBase58();
console.log(`initialize_signature=${signature}`);
console.log(`trade_pda=${tradePda}`);
console.log(`vault_token_account=${vault}`);

const confirmed = await request(`/trades/${tradeId}/blockchain/initialize/confirm`, 'POST', {
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
