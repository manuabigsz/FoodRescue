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

const producer = Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`${base}/producer.json`, 'utf8'))));
const programId = new PublicKey(process.env.PROGRAM_ID);
const connection = new Connection(rpc, 'confirmed');

/**
 * As seeds vêm autodescritas na preparação (`{ type, value }`), então o script
 * não repete a regra de derivação — acompanha o backend e o programa sozinho.
 */
function seedBuffer(seed) {
  if (seed.type === 'utf8') return Buffer.from(seed.value, 'utf8');
  if (seed.type === 'base64') return Buffer.from(seed.value, 'base64');
  if (seed.type === 'pubkey') return new PublicKey(seed.value).toBuffer();
  throw new Error(`Tipo de semente não suportado na preparação: ${seed.type}`);
}

async function request(path, method = 'GET', payload, token) {
  const response = await fetch(`${api}${path}`, { method, headers: { 'content-type': 'application/json', authorization: `Bearer ${token || process.env.API_TOKEN || actors.ngo.token}` }, body: payload === undefined ? undefined : JSON.stringify(payload) });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(`${method} ${path} ${response.status}: ${JSON.stringify(body)}`);
  return body.data ?? body;
}

/** A ordem e a quantidade de contas vêm da preparação, não de uma lista fixa. */
function buildInstruction(prepared, derived) {
  const keys = prepared.instruction.accounts.map((item) => {
    const pubkey = item.pubkey ? new PublicKey(item.pubkey) : derived[item.name];
    if (!pubkey) throw new Error(`Não sei derivar a conta ${item.name}; a preparação não trouxe pubkey.`);

    return { pubkey, isSigner: Boolean(item.signer), isWritable: Boolean(item.writable) };
  });

  return new TransactionInstruction({ programId, keys, data: Buffer.from(prepared.instruction.data_base64, 'base64') });
}

async function main() {
  /**
   * A atestação acontece em duas transações: a NGO abre, o produtor confirma.
   * Cada parte assina sozinha — duas assinaturas na mesma transação não
   * sobreviveriam a dois atores assinando em momentos diferentes.
   */
  const prepared = await request(`/trades/${tradeId}/rescue-proof/prepare`, 'POST');
  const proofPda = PublicKey.findProgramAddressSync(prepared.pda_seeds.rescue.map(seedBuffer), programId)[0];

  const signature = await sendAndConfirmTransaction(
    connection,
    new Transaction().add(buildInstruction(prepared, { rescue_proof_pda: proofPda })),
    [ngo],
    { commitment: 'confirmed' },
  );
  console.log(`rescue_proof_signature=${signature}`);
  console.log(`rescue_proof_pda=${proofPda.toBase58()}`);
  const opened = await request(`/trades/${tradeId}/rescue-proof/confirm`, 'POST', { signature, proof_pda: proofPda.toBase58() });
  console.log(`rescue_backend_status=${opened.status || 'proof_pending'}`);

  const producerToken = process.env.PRODUCER_API_TOKEN || actors.producer.token;
  const producerPrepared = await request(`/trades/${tradeId}/rescue-proof/producer/prepare`, 'POST', undefined, producerToken);
  const producerSignature = await sendAndConfirmTransaction(
    connection,
    new Transaction().add(buildInstruction(producerPrepared, {})),
    [producer],
    { commitment: 'confirmed' },
  );
  console.log(`rescue_proof_producer_signature=${producerSignature}`);
  const confirmed = await request(`/trades/${tradeId}/rescue-proof/producer/confirm`, 'POST', { signature: producerSignature }, producerToken);
  console.log(`rescue_backend_status=${confirmed.status || 'completed'}`);

  fs.writeFileSync(`${base}/donation-rescue-proof.local.json`, `${JSON.stringify({ signature, producer_signature: producerSignature, proof_pda: proofPda.toBase58(), metadata_hash: prepared.metadata_hash, confirmed }, null, 2)}\n`, { mode: 0o600 });
}
main().catch((error) => { console.error(error.stack || error.message || error); process.exitCode = 1; });
