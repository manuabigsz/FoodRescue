const fs = require('node:fs');
const base = '/workspace/keypar/devnet-e2e';
const api = process.env.API_BASE_URL || 'http://food-rescue:8080/api/v1';
const actors = JSON.parse(fs.readFileSync(`${base}/actors.local.json`, 'utf8'));
const trade = JSON.parse(fs.readFileSync(`${base}/donation.local.json`, 'utf8'));
const signature = process.env.DONATION_SETTLEMENT_SIGNATURE;
if (!signature) throw new Error('Informe DONATION_SETTLEMENT_SIGNATURE.');
async function main() {
  const response = await fetch(`${api}/trades/${trade.trade_id}/blockchain/settlement/confirm`, { method: 'POST', headers: { 'content-type': 'application/json', authorization: `Bearer ${actors.ngo.token}` }, body: JSON.stringify({ signature }) });
  const body = await response.json();
  if (!response.ok) throw new Error(`${response.status}: ${JSON.stringify(body)}`);
  console.log(`donation_backend_status=${body.data?.status || body.data?.trade_status}`);
  fs.writeFileSync(`${base}/donation-blockchain-settlement.local.json`, `${JSON.stringify({ signature, confirmed: body.data }, null, 2)}\n`, { mode: 0o600 });
}
main().catch((error) => { console.error(error.stack || error.message || error); process.exitCode = 1; });
