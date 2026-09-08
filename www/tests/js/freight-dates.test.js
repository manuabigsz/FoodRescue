import { describe, expect, it } from 'vitest';
import { bootApp, flush, sampleUser } from './helpers/boot.js';

const carrier = { ...sampleUser, roles: ['carrier'] };
const request = {
    id: 5,
    trade_id: 10,
    origin: { city: 'Mogi das Cruzes', state: 'SP' },
    destination: { city: 'São Paulo', state: 'SP' },
    quantity: '680.000',
    unit: 'kg',
    quotation_expires_at: new Date(Date.now() + 3600000).toISOString(),
};

function boot(routes = {}) {
    return bootApp({
        hash: '#/fretes',
        session: { user: carrier },
        routes: { 'GET /auth/me': { data: carrier }, 'GET /shipping-requests': { data: [request] }, ...routes },
    });
}

/** O input datetime-local trabalha em hora local; toISOString devolveria UTC. */
function local(date) {
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}

function campos() {
    const form = document.querySelector('[data-freight-form="5"]');

    return {
        form,
        pickup: form.querySelector('[name="pickup_at"]'),
        delivery: form.querySelector('[name="estimated_delivery_at"]'),
        hint: form.querySelector('[data-transit]'),
        message: form.querySelector('[data-form-message]'),
    };
}

async function change(input, value) {
    input.value = value;
    input.dispatchEvent(new window.Event('change'));
    await flush();
}

describe('datas da cotação de frete', () => {
    it('já vem preenchida com coleta amanhã e entrega oito horas depois', async () => {
        await boot();
        const { pickup, delivery, hint } = campos();

        expect(pickup.value).toMatch(/^\d{4}-\d{2}-\d{2}T08:00$/);
        const amanha = new Date();
        amanha.setDate(amanha.getDate() + 1);
        expect(pickup.value.slice(0, 10)).toBe(local(amanha).slice(0, 10));
        expect(new Date(delivery.value) - new Date(pickup.value)).toBe(8 * 3600000);
        expect(hint.textContent).toBe('8h de trânsito');
    });

    it('impede escolher data passada pelo atributo min', async () => {
        await boot();
        const { pickup, delivery } = campos();

        expect(pickup.min).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/);
        expect(new Date(pickup.min) <= new Date()).toBe(true);
        expect(delivery.min).toBe(pickup.value);
    });

    it('adiar a coleta empurra a entrega e preserva a duração', async () => {
        await boot();
        const { pickup, delivery } = campos();
        const original = new Date(pickup.value);

        const novo = new Date(original.getTime() + 48 * 3600000);
        await change(pickup, local(novo));

        expect(new Date(delivery.value) - new Date(pickup.value)).toBe(8 * 3600000);
        expect(delivery.min).toBe(pickup.value);
    });

    it('o tempo de trânsito acompanha o que foi digitado', async () => {
        await boot();
        const { pickup, delivery, hint } = campos();

        await change(delivery, local(new Date(new Date(pickup.value).getTime() + 30 * 3600000)));

        expect(hint.textContent).toBe('1 dia e 6h de trânsito');
        expect(hint.classList.contains('invalid')).toBe(false);
    });

    it('entrega antes da coleta é sinalizada e barrada antes de chamar a API', async () => {
        const { calls } = await boot();
        const { form, pickup, delivery, hint, message } = campos();

        delivery.value = local(new Date(new Date(pickup.value).getTime() - 3600000));
        delivery.dispatchEvent(new window.Event('change'));
        await flush();

        expect(hint.textContent).toBe('A entrega precisa ser depois da coleta');
        expect(hint.classList.contains('invalid')).toBe(true);

        form.querySelector('[name="amount"]').value = 'US$ 480.00';
        form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
        await flush();

        expect(message.textContent).toBe('A entrega precisa ser depois da coleta.');
        expect(calls.some((call) => call.path.includes('/offers'))).toBe(false);
    });

    it('coleta no passado é barrada antes de chamar a API', async () => {
        const { calls } = await boot();
        const { form, pickup, delivery, message } = campos();

        pickup.value = local(new Date(Date.now() - 3600000));
        delivery.value = local(new Date(Date.now() + 3600000));
        form.querySelector('[name="amount"]').value = 'US$ 480.00';
        form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
        await flush();

        expect(message.textContent).toBe('A coleta precisa ser no futuro.');
        expect(calls.some((call) => call.path.includes('/offers'))).toBe(false);
    });

    it('com datas válidas envia em ISO e o par continua coerente', async () => {
        const { calls } = await boot({ 'POST /shipping-requests/5/offers': { status: 201, body: { data: { id: 9 } } } });
        const { form, pickup, delivery } = campos();

        form.querySelector('[name="amount"]').value = 'US$ 480.00';
        form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
        await flush();

        const body = calls.find((call) => call.path === '/shipping-requests/5/offers').body;
        expect(body.pickup_at).toBe(new Date(pickup.value).toISOString());
        expect(new Date(body.estimated_delivery_at) > new Date(body.pickup_at)).toBe(true);
        expect(new Date(body.pickup_at) > new Date()).toBe(true);
    });
});

describe('cotação já enviada', () => {
    const enviada = {
        id: 9,
        status: 'pending',
        amount: '480.000000',
        pickup_at: new Date(Date.now() + 26 * 3600000).toISOString(),
        estimated_delivery_at: new Date(Date.now() + 34 * 3600000).toISOString(),
    };

    function comCotacao(routes = {}) {
        return bootApp({
            hash: '#/fretes',
            session: { user: carrier },
            routes: {
                'GET /auth/me': { data: carrier },
                'GET /shipping-requests': { data: [{ ...request, offers: [enviada] }] },
                ...routes,
            },
        });
    }

    it('mostra o valor enviado em vez de um formulário vazio', async () => {
        await comCotacao();
        const { form } = campos();

        expect(document.querySelector('.quote-status').textContent).toContain('480,00 FRUSD');
        expect(form.querySelector('[name=amount]').value).toBe('US$ 480.000000');
        expect(form.querySelector('[type=submit]').textContent).toBe('Atualizar cotação');
    });

    it('reaproveita as datas que foram enviadas', async () => {
        await comCotacao();
        const { pickup, delivery } = campos();

        expect(new Date(pickup.value).getTime()).toBe(new Date(enviada.pickup_at).setSeconds(0, 0));
        expect(new Date(delivery.value) - new Date(pickup.value)).toBe(8 * 3600000);
    });

    it('salvar usa PATCH na própria proposta, sem criar outra', async () => {
        const { calls } = await comCotacao({ 'PATCH /shipping-offers/9': { data: { ...enviada, amount: '390.000000' } } });
        const { form } = campos();

        form.querySelector('[name=amount]').value = 'US$ 390.00';
        form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
        await flush();

        const patch = calls.find((call) => call.method === 'PATCH');
        expect(patch.path).toBe('/shipping-offers/9');
        expect(patch.body.amount).toBe('390.00');
        expect(calls.some((call) => call.method === 'POST' && call.path.includes('/offers'))).toBe(false);
    });

    it('sem cotação própria o envio continua sendo POST', async () => {
        const { calls } = await boot({ 'POST /shipping-requests/5/offers': { status: 201, body: { data: { id: 9 } } } });
        const { form } = campos();

        form.querySelector('[name=amount]').value = 'US$ 480.00';
        form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
        await flush();

        expect(calls.some((call) => call.method === 'POST' && call.path === '/shipping-requests/5/offers')).toBe(true);
        expect(calls.some((call) => call.method === 'PATCH')).toBe(false);
    });
});
