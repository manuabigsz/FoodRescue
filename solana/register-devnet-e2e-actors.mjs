import crypto from 'node:crypto';
import fs from 'node:fs';
import nacl from 'tweetnacl';
import { Keypair } from '@solana/web3.js';

const api = process.env.API_BASE_URL ?? 'http://food-rescue:8080/api/v1';
const statePath = process.env.ACTOR_STATE ?? '/workspace/keypar/devnet-e2e/actors.local.json';

const actors = [
    ['buyer', 'buyer', 'FyEbrkBEeoyNF3HyyaMbc51cKNL1X1uoysb2qdfoy3Qe', { buyer_type: 'individual', document_number: 'E2E-BUYER-001' }],
    ['producer', 'producer', 'ATB7z1UtmGPGgMbvvcMfLuXdBvtsfbD1k77A4yHymvap', { producer_type: 'individual', document_number: 'E2E-PRODUCER-001', farm_name: 'FoodRescue E2E Farm' }],
    ['carrier', 'carrier', '723bi7HcVzgTX2W8tm3jTkbMDP4WZJLEq8aYXTMeXXUt', { document_number: 'E2E-CARRIER-001', company_name: 'FoodRescue E2E Logistics', contact_name: 'E2E Carrier', service_regions: ['Devnet'], vehicle_types: ['van'], max_capacity_kg: 1000 }],
    ['ngo', 'ngo', '84FPXwWEEymP14ZgafXMMbqueGTq8S6wsKZgndzwpd5n', { organization_name: 'FoodRescue E2E NGO', registration_number: 'E2E-NGO-001', contact_name: 'E2E NGO' }],
];

const common = {
    phone: '+5511999990000',
    country: 'Brazil',
    state: 'SP',
    city: 'Sao Paulo',
    address_line: 'Devnet Test Street 1',
    postal_code: '01000000',
};

async function request(path, options = {}) {
    const response = await fetch(`${api}${path}`, {
        ...options,
        headers: { 'content-type': 'application/json', ...(options.headers ?? {}) },
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(`${path} ${response.status}: ${JSON.stringify(body)}`);
    return body.data ?? body;
}

function sign(secret, message) {
    return Buffer.from(nacl.sign.detached(Buffer.from(message), secret)).toString('base64');
}

const existing = fs.existsSync(statePath) ? JSON.parse(fs.readFileSync(statePath, 'utf8')) : {};
const result = {};

for (const [role, keyRole, wallet, profile] of actors) {
    const keypair = Keypair.fromSecretKey(Uint8Array.from(JSON.parse(fs.readFileSync(`/workspace/keypar/devnet-e2e/${keyRole}.json`, 'utf8'))));
    const email = existing[role]?.email ?? `foodrescue-e2e-${role}@devnet.test`;
    const password = existing[role]?.password ?? `E2eDevnet-${crypto.randomBytes(12).toString('base64url')}!aA1`;
    let token;

    if (!existing[role]?.user_id) {
        const challenge = await request('/auth/wallet/challenge', {
            method: 'POST',
            body: JSON.stringify({ wallet_address: wallet }),
        });
        const registered = await request('/auth/register', {
            method: 'POST',
            body: JSON.stringify({
                name: `FoodRescue E2E ${role}`,
                email,
                password,
                password_confirmation: password,
                role,
                solana_wallet_address: wallet,
                wallet_challenge_id: challenge.id,
                wallet_signature: sign(keypair.secretKey, challenge.message),
                profile: { ...common, ...profile },
            }),
        });
        result[role] = { user_id: registered.id, email, password, wallet };
    } else {
        result[role] = existing[role];
    }

    const login = await request('/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email, password, device_name: 'devnet-e2e' }),
    });
    result[role].token = login.token;
    console.log(`${role}: user_id=${result[role].user_id} wallet=${wallet}`);
}

fs.writeFileSync(statePath, `${JSON.stringify(result, null, 2)}\n`, { mode: 0o600 });
console.log(`Actor state saved at ${statePath}`);
