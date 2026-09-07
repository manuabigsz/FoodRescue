const fs = require('node:fs');
const api = process.env.API_BASE_URL || 'http://food-rescue:8080/api/v1';
const base = '/workspace/keypar/devnet-e2e';
const actors = JSON.parse(fs.readFileSync(`${base}/actors.local.json`, 'utf8'));
const statePath = `${base}/donation.local.json`;
const state = fs.existsSync(statePath) ? JSON.parse(fs.readFileSync(statePath, 'utf8')) : {};

async function request(path, actor, method = 'GET', payload) {
  const response = await fetch(`${api}${path}`, { method, headers: { 'content-type': 'application/json', authorization: `Bearer ${actors[actor].token}` }, body: payload === undefined ? undefined : JSON.stringify(payload) });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(`${method} ${path} ${response.status}: ${JSON.stringify(body)}`);
  return body.data ?? body;
}
const future = (minutes) => new Date(Date.now() + minutes * 60_000).toISOString();

async function main() {
  if (!state.surplus_lot_id) {
    const lot = await request('/surplus', 'producer', 'POST', {
      agricultural_product_id: 1, quantity: '50.000', unit: 'kg', origin_address: 'E2E Donation Farm 1', origin_city: 'Sao Paulo', origin_state: 'SP', origin_country: 'BR',
      harvest_date: new Date().toISOString().slice(0, 10), available_until: future(180), asking_price: '50.000000', minimum_price: '50.000000', donation_eligible: true, accepted_logistics_modes: ['third_party_carrier'],
    });
    state.surplus_lot_id = lot.id;
    console.log(`donation_surplus_lot_id=${lot.id}`);
  }
  if (!state.trade_id) {
    const trade = await request(`/surplus/${state.surplus_lot_id}/donations/accept`, 'ngo', 'POST');
    state.trade_id = trade.id;
    console.log(`donation_trade_id=${trade.id}`);
  }
  if (!state.shipping_request_id) {
    const shipping = await request(`/trades/${state.trade_id}/shipping`, 'ngo', 'POST', { destination_address: 'E2E NGO Destination 1', destination_city: 'Sao Paulo', destination_state: 'SP', destination_country: 'BR' });
    state.shipping_request_id = shipping.id;
    console.log(`donation_shipping_request_id=${shipping.id}`);
  }
  if (!state.shipping_offer_id) {
    const offer = await request(`/shipping-requests/${state.shipping_request_id}/offers`, 'carrier', 'POST', { amount: '10.000000', pickup_at: future(10), estimated_delivery_at: future(60), expires_at: future(30) });
    state.shipping_offer_id = offer.id;
    console.log(`donation_shipping_offer_id=${offer.id}`);
  }
  if (!state.shipping_selected) {
    await request(`/trades/${state.trade_id}/shipping-offers/${state.shipping_offer_id}/select`, 'ngo', 'POST');
    state.shipping_selected = true;
    console.log('donation_shipping_selected=true');
  }
  fs.writeFileSync(statePath, `${JSON.stringify(state, null, 2)}\n`, { mode: 0o600 });
  console.log(`state=${statePath}`);
}
main().catch((error) => { console.error(error.stack || error.message || error); process.exitCode = 1; });
