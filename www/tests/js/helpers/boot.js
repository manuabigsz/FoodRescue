import { readFileSync } from 'node:fs';
import { vi } from 'vitest';

/**
 * O shell da página vem do próprio Blade, e não de uma cópia no teste: se a view
 * perder um elemento que o JS procura, os testes quebram junto.
 */
export function domShell() {
    const blade = readFileSync('resources/views/welcome.blade.php', 'utf8');
    const body = blade.slice(blade.indexOf('<body>') + '<body>'.length, blade.indexOf('</body>'));

    return body.replace(/@fonts|@vite\([^)]*\)/g, '');
}

export const sampleUser = {
    id: 7,
    name: 'Sítio Boa Colheita',
    email: 'produtor@foodrescue.test',
    roles: ['producer'],
    status: 'active',
    solana_wallet_address: 'ATB7z1UtmGPGgMbvvcMfLuXdBvtsfbD1k77A4yHymvap',
    solana_wallet_verified: true,
};

export function lot(overrides = {}) {
    return {
        id: 1,
        product: { name: 'Tomate italiano' },
        quality_grade: { name: 'Tipo B' },
        quantity: '680.000',
        unit: 'kg',
        origin: { city: 'Mogi das Cruzes', state: 'SP' },
        asking_price: '2.350000',
        minimum_price: null,
        available_until: new Date(Date.now() + 86400000).toISOString(),
        donation_eligible: true,
        status: 'open',
        ...overrides,
    };
}

export function trade(overrides = {}) {
    return {
        id: 10,
        surplus_lot_id: 1,
        producer_id: 7,
        buyer_id: 8,
        status: 'reserved',
        is_donation: false,
        product_amount: '1598.000000',
        shipping_amount: '0.000000',
        buyer_total: '1598.000000',
        payment_expires_at: null,
        shipping_request: null,
        blockchain: null,
        rescue_proof: null,
        surplus_lot: lot(),
        ...overrides,
    };
}

export function paginated(data, meta = {}) {
    return { data, links: {}, meta: { current_page: 1, last_page: 1, total: data.length, ...meta } };
}

/**
 * Carrega o app num DOM limpo. `routes` mapeia "METHOD /caminho" para a resposta;
 * o caminho é comparado sem a query string, e uma função recebe a requisição.
 */
/**
 * O app registra listeners em window/document ao ser importado. Como o jsdom é
 * compartilhado pelo arquivo de teste inteiro, sem remover os do boot anterior
 * várias instâncias respondem ao mesmo hashchange e disputam o DOM.
 */
let listenersDoBootAnterior = [];

function isolarListeners() {
    listenersDoBootAnterior.forEach(function ([alvo, tipo, fn]) { alvo.removeEventListener(tipo, fn); });
    listenersDoBootAnterior = [];

    const originais = new Map();
    [window, document].forEach(function (alvo) {
        const original = alvo.addEventListener.bind(alvo);
        originais.set(alvo, original);
        alvo.addEventListener = function (tipo, fn, opcoes) {
            listenersDoBootAnterior.push([alvo, tipo, fn]);
            original(tipo, fn, opcoes);
        };
    });

    return function restaurar() {
        originais.forEach(function (original, alvo) { alvo.addEventListener = original; });
    };
}

export async function bootApp({ routes = {}, session = null, hash = '#/' } = {}) {
    const restaurarListeners = isolarListeners();
    document.body.innerHTML = domShell();
    window.scrollTo = vi.fn();
    sessionStorage.clear();
    window.location.hash = hash;
    // O jsdom entrega hashchange numa tarefa própria: drenar aqui evita que o
    // evento desta troca chegue depois e sobrescreva uma navegação do teste.
    await flush(2);

    if (session) {
        sessionStorage.setItem('foodrescue_token', session.token || 'test-token');
        sessionStorage.setItem('foodrescue_user', JSON.stringify(session.user || sampleUser));
        if (session.expiresAt) sessionStorage.setItem('foodrescue_token_expires_at', session.expiresAt);
    }

    const calls = [];
    global.fetch = vi.fn(async (url, init = {}) => {
        const method = init.method || 'GET';
        const [path, query] = String(url).replace('/api/v1', '').split('?');
        const key = method + ' ' + path;
        calls.push({ method, path, query: query || '', body: init.body ? JSON.parse(init.body) : null, headers: init.headers });

        const handler = routes[key];
        if (handler === undefined) {
            return jsonResponse(404, { message: 'Rota não mapeada no teste: ' + key });
        }

        const result = typeof handler === 'function' ? await handler(calls.at(-1)) : handler;

        return jsonResponse(result.status ?? 200, result.body ?? result);
    });

    vi.resetModules();
    await import('../../../resources/js/app.js');
    restaurarListeners();
    await flush();

    return { calls, fetch: global.fetch };
}

function jsonResponse(status, body) {
    return {
        ok: status >= 200 && status < 300,
        status,
        headers: { get: () => null },
        json: async () => body,
    };
}

/** Deixa a fila de microtarefas drenar — as telas fazem fetch encadeado. */
export async function flush(times = 8) {
    for (let i = 0; i < times; i++) {
        await Promise.resolve();
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

export function text(selector) {
    return document.querySelector(selector)?.textContent?.trim() ?? null;
}

export async function click(selector) {
    document.querySelector(selector).click();
    await flush();
}

export async function navigate(hash) {
    window.location.hash = hash;
    window.dispatchEvent(new window.HashChangeEvent('hashchange'));
    await flush();
}
