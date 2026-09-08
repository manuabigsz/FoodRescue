const fs = require('node:fs');
const base = process.env.KEYPAIR_DIR || '/workspace/keypar/devnet-e2e';
const api = process.env.API_BASE_URL || 'http://food-rescue:8080/api/v1';
const actors = JSON.parse(fs.readFileSync(`${base}/actors.local.json`, 'utf8'));

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
const state = fs.existsSync(`${base}/blockchain-settlement.local.json`)
  ? JSON.parse(fs.readFileSync(`${base}/blockchain-settlement.local.json`, 'utf8'))
  : { signature: process.env.SETTLEMENT_SIGNATURE };
if (!state.signature) throw new Error('Informe SETTLEMENT_SIGNATURE.');
const trade = JSON.parse(fs.readFileSync(`${base}/trade.local.json`, 'utf8'));
async function main() {
const response = await fetch(`${api}/trades/${tradeId}/blockchain/settlement/confirm`, {
  method: 'POST',
  headers: { 'content-type': 'application/json', authorization: `Bearer ${process.env.API_TOKEN || actors.buyer.token}` },
  body: JSON.stringify({ signature: state.signature }),
});
const body = await response.json();
if (!response.ok) throw new Error(`${response.status}: ${JSON.stringify(body)}`);
console.log(`backend_status=${body.data?.status || 'completed'}`);
fs.writeFileSync(`${base}/blockchain-settlement.local.json`, `${JSON.stringify({ ...state, confirmed: body.data }, null, 2)}\n`, { mode: 0o600 });
}
main().catch((error) => { console.error(error.stack || error.message || error); process.exitCode = 1; });
