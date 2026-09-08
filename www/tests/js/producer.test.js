import { describe, expect, it } from 'vitest';
import { bootApp, click, flush, lot, paginated, sampleUser } from './helpers/boot.js';

const producer = { ...sampleUser, roles: ['producer'] };
const catalogRoutes = {
    'GET /catalog/products': { data: [{ id: 3, name: 'Tomate italiano', slug: 'tomate', active: true }] },
    'GET /catalog/quality-grades': { data: [{ id: 4, name: 'Tipo B', active: true }] },
};

describe('publicar excedente', () => {
    it('preenche os selects com o catálogo de referência', async () => {
        await bootApp({
            hash: '#/publicar',
            session: { user: producer },
            routes: { 'GET /auth/me': { data: producer }, ...catalogRoutes },
        });

        expect(document.querySelector('#product').textContent).toContain('Tomate italiano');
        expect(document.querySelector('#grade').textContent).toContain('Tipo B');
    });

    it('bloqueia quem não é produtor', async () => {
        await bootApp({
            hash: '#/publicar',
            session: { user: { ...sampleUser, roles: ['buyer'] } },
            routes: { 'GET /auth/me': { data: { ...sampleUser, roles: ['buyer'] } } },
        });

        expect(document.body.textContent).toContain('Disponível para produtores');
        expect(document.querySelector('[data-publish-form]')).toBeNull();
    });

    it('avisa quando o administrador ainda não cadastrou produtos', async () => {
        await bootApp({
            hash: '#/publicar',
            session: { user: producer },
            routes: { 'GET /auth/me': { data: producer }, 'GET /catalog/products': { data: [] }, 'GET /catalog/quality-grades': { data: [] } },
        });

        expect(document.body.textContent).toContain('Nenhum produto cadastrado');
    });

    it('monta o payload no formato que a API valida', async () => {
        const { calls } = await bootApp({
            hash: '#/publicar',
            session: { user: producer },
            routes: {
                'GET /auth/me': { data: producer },
                ...catalogRoutes,
                'POST /surplus': { status: 201, body: { data: { id: 77 } } },
                'GET /surplus': paginated([]),
            },
        });

        const form = document.querySelector('[data-publish-form]');
        form.querySelector('[name="quantity"]').value = '680';
        form.querySelector('[name="asking_price"]').value = '2.35';
        form.querySelector('[name="origin_address"]').value = 'Zona Rural, km 12';
        form.querySelector('[name="origin_city"]').value = 'Mogi das Cruzes';
        form.querySelector('[name="origin_state"]').value = 'SP';
        form.querySelector('[name="donation_eligible"]').checked = true;
        form.querySelector('#grade').value = '4';
        form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
        await flush();

        const body = calls.find((call) => call.method === 'POST' && call.path === '/surplus').body;
        expect(body.agricultural_product_id).toBe(3);
        expect(body.quality_grade_id).toBe(4);
        expect(body.origin_country).toBe('BR');
        expect(body.donation_eligible).toBe(true);
        expect(body.accepted_logistics_modes).toEqual(['buyer_pickup', 'third_party_carrier']);
        expect(body.available_until).toMatch(/T.*Z$/);
        expect(window.location.hash).toBe('#/catalogo');
    });

    it('exige ao menos uma modalidade de logística antes de enviar', async () => {
        const { calls } = await bootApp({
            hash: '#/publicar',
            session: { user: producer },
            routes: { 'GET /auth/me': { data: producer }, ...catalogRoutes },
        });

        const form = document.querySelector('[data-publish-form]');
        form.querySelectorAll('[name="accepted_logistics_modes"]').forEach((box) => { box.checked = false; });
        form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
        await flush();

        expect(document.querySelector('[data-form-message]').textContent).toContain('modalidade de logística');
        expect(calls.some((call) => call.method === 'POST' && call.path === '/surplus')).toBe(false);
    });

    it('erro de validação da API aparece no formulário', async () => {
        await bootApp({
            hash: '#/publicar',
            session: { user: producer },
            routes: {
                'GET /auth/me': { data: producer },
                ...catalogRoutes,
                'POST /surplus': { status: 422, body: { message: 'Dados inválidos.', errors: { asking_price: ['O campo preço pedido deve ser um número.'] } } },
            },
        });

        const form = document.querySelector('[data-publish-form]');
        form.querySelector('[name="quantity"]').value = '1';
        form.querySelector('[name="asking_price"]').value = 'x';
        form.querySelector('[name="origin_address"]').value = 'a';
        form.querySelector('[name="origin_city"]').value = 'b';
        form.querySelector('[name="origin_state"]').value = 'c';
        form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
        await flush();

        expect(document.querySelector('[data-form-message]').textContent).toBe('O campo preço pedido deve ser um número.');
    });
});

describe('meus lotes', () => {
    function boot(lots, extra = {}) {
        return bootApp({
            hash: '#/meus-lotes',
            session: { user: producer },
            routes: { 'GET /auth/me': { data: producer }, 'GET /surplus': { data: lots }, ...extra },
        });
    }

    it('pede o histórico completo com mine=1', async () => {
        const { calls } = await boot([lot()]);

        expect(calls.find((call) => call.path === '/surplus').query).toContain('mine=1');
    });

    it('mostra editar e cancelar só em lote aberto', async () => {
        await boot([lot({ id: 1, status: 'open' }), lot({ id: 2, status: 'reserved' })]);

        expect(document.querySelector('[data-edit-lot="1"]')).not.toBeNull();
        expect(document.querySelector('[data-cancel-lot="1"]')).not.toBeNull();
        expect(document.querySelector('[data-edit-lot="2"]')).toBeNull();
        expect(document.querySelector('[data-cancel-lot="2"]')).toBeNull();
    });

    it('lista as propostas do lote e aceita uma', async () => {
        const { calls } = await boot([lot()], {
            'GET /surplus/1/offers': {
                data: [{ id: 9, amount: '19000.000000', status: 'pending', buyer: { id: 8, name: 'Mercado Central' }, expires_at: null }],
            },
            'POST /offers/9/accept': { status: 201, body: { data: { id: 30 } } },
        });

        await click('[data-offers="1"]');
        expect(document.querySelector('.quote-row').textContent).toContain('Mercado Central');

        await click('[data-accept-offer="9"]');
        expect(calls.some((call) => call.path === '/offers/9/accept')).toBe(true);
    });

    it('editar envia só os campos alterados', async () => {
        const { calls } = await boot([lot({ asking_price: '2.350000', quantity: '680.000' })], {
            'PATCH /surplus/1': { data: lot({ asking_price: '1.900000' }) },
        });

        await click('[data-edit-lot="1"]');
        const form = document.querySelector('[data-edit-form]');
        form.querySelector('[name="asking_price"]').value = '1.9';
        form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
        await flush();

        const patch = calls.find((call) => call.method === 'PATCH' && call.path === '/surplus/1');
        expect(patch.body).toEqual({ asking_price: '1.9' });
    });

    it('editar sem mudar nada não chama a API', async () => {
        const { calls } = await boot([lot()]);

        await click('[data-edit-lot="1"]');
        document.querySelector('[data-edit-form]').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
        await flush();

        expect(calls.some((call) => call.method === 'PATCH')).toBe(false);
        expect(document.querySelector('[data-panel-message]').textContent).toBe('Nada foi alterado.');
    });
});
