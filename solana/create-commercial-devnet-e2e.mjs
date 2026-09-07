import fs from 'node:fs';

const api = process.env.API_BASE_URL ?? 'http://food-rescue:8080/api/v1';
const actorPath = '/workspace/keypar/devnet-e2e/actors.local.json';
const statePath = '/workspace/keypar/devnet-e2e/trade.local.json';
const actors = JSON.parse(fs.readFileSync(actorPath, 'utf8'));
const state = fs.existsSync(statePath) ? JSON.parse(fs.readFileSync(statePath, 'utf8')) : {};

async function request(path, token, method = 'GET', payload = undefined) {
    const response = await fetch(`${api}${path}`, {
        method,
        headers: { 'content-type': 'application/json', authorization: `Bearer ${token}` },
        body: payload === undefined ? undefined : JSON.stringify(payload),
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(`${method} ${path} ${response.status}: ${JSON.stringify(body)}`);
    return body.data ?? body;
}

const future = (minutes) => new Date(Date.now() + minutes * 60_000).toISOString();

if (!state.surplus_lot_id) {
    const lot = await request('/surplus', actors.producer.token, 'POST', {
        agricultural_product_id: 1,
        quantity: '100.000',
        unit: 'kg',
        origin_address: 'E2E Farm Street 1',
        origin_city: 'Sao Paulo',
        origin_state: 'SP',
        origin_country: 'BR',
        harvest_date: new Date().toISOString().slice(0, 10),
        available_until: future(180),
        asking_price: '100.000000',
        minimum_price: '100.000000',
        donation_eligible: true,
        accepted_logistics_modes: ['third_party_carrier'],
    });
    state.surplus_lot_id = lot.id;
    console.log(`surplus_lot_id=${lot.id}`);
}

if (!state.trade_id) {
    const trade = await request(`/surplus/${state.surplus_lot_id}/buy-now`, actors.buyer.token, 'POST');
    state.trade_id = trade.id;
    console.log(`trade_id=${trade.id}`);
}

if (!state.shipping_request_id) {
    const shipping = await request(`/trades/${state.trade_id}/shipping`, actors.buyer.token, 'POST', {
        destination_address: 'E2E Destination 1',
        destination_city: 'Sao Paulo',
        destination_state: 'SP',
        destination_country: 'BR',
    });
    state.shipping_request_id = shipping.id;
    console.log(`shipping_request_id=${shipping.id}`);
}

if (!state.shipping_offer_id) {
    const offer = await request(`/shipping-requests/${state.shipping_request_id}/offers`, actors.carrier.token, 'POST', {
        amount: '10.000000',
        pickup_at: future(10),
        estimated_delivery_at: future(60),
        expires_at: future(30),
    });
    state.shipping_offer_id = offer.id;
    console.log(`shipping_offer_id=${offer.id}`);
}

if (!state.shipping_selected) {
    await request(`/trades/${state.trade_id}/shipping-offers/${state.shipping_offer_id}/select`, actors.buyer.token, 'POST');
    state.shipping_selected = true;
    console.log('shipping_selected=true');
}

fs.writeFileSync(statePath, `${JSON.stringify(state, null, 2)}\n`, { mode: 0o600 });
console.log(`state=${statePath}`);
