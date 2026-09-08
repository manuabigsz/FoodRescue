import { describe, expect, it } from 'vitest';
import { bootApp, click, flush, lot, navigate, paginated, sampleUser } from './helpers/boot.js';

describe('catálogo de excedentes', () => {
    it('mostra os lotes da API e não marca a tela como demonstração', async () => {
        await bootApp({
            hash: '#/catalogo',
            session: { user: { ...sampleUser, roles: ['buyer'] } },
            routes: {
                'GET /auth/me': { data: { ...sampleUser, roles: ['buyer'] } },
                'GET /surplus': paginated([lot(), lot({ id: 2, product: { name: 'Cenoura' }, donation_eligible: false })]),
            },
        });

        expect(document.querySelectorAll('[data-lot-card]')).toHaveLength(2);
        expect(document.body.textContent).toContain('Tomate italiano');
        expect(document.querySelector('.demo-banner')).toBeNull();
    });

    it('não mostra ações comerciais para o produtor dono do lote', async () => {
        await bootApp({
            hash: '#/catalogo',
            session: { user: sampleUser },
            routes: {
                'GET /auth/me': { data: sampleUser },
                'GET /surplus': paginated([lot({ producer: { id: sampleUser.id, name: sampleUser.name }, donation_eligible: true })]),
            },
        });

        expect(document.querySelector('[data-buy="1"]')).toBeNull();
        expect(document.querySelector('[data-donate="1"]')).toBeNull();
        expect(document.querySelector('[data-lot-card]').textContent).toContain('Este lote pertence a você.');
    });

    it('formata quantidades decimais sem transformar 150.000 em 150000', async () => {
        await bootApp({
            hash: '#/catalogo',
            session: { user: { ...sampleUser, roles: ['buyer'] } },
            routes: {
                'GET /auth/me': { data: { ...sampleUser, roles: ['buyer'] } },
                'GET /surplus': paginated([lot({ quantity: '150.000', unit: 'unit' }), lot({ id: 2, quantity: '120.000', unit: 'kg' })]),
            },
        });

        const text = document.querySelector('[data-catalog]').textContent;
        expect(text).toContain('150 unit');
        expect(text).toContain('120 kg');
        expect(text).not.toContain('150.000 unit');
        expect(text).not.toContain('120.000 kg');
    });

    it('envia os filtros para a API em vez de filtrar na tela', async () => {
        const { calls } = await bootApp({
            hash: '#/catalogo',
            session: { user: { ...sampleUser, roles: ['buyer'] } },
            routes: {
                'GET /auth/me': { data: sampleUser },
                'GET /surplus': paginated([lot()]),
            },
        });

        const select = document.querySelector('#donation-filter');
        select.value = '1';
        select.dispatchEvent(new window.Event('change'));
        await flush();

        const last = calls.filter((call) => call.path === '/surplus').at(-1);
        expect(last.query).toContain('donation_eligible=1');
        expect(last.query).toContain('sort=urgency');
        expect(last.query).toContain('page=1');
    });

    it('manda a busca como parâmetro depois do debounce', async () => {
        const { calls } = await bootApp({
            hash: '#/catalogo',
            session: { user: { ...sampleUser, roles: ['buyer'] } },
            routes: { 'GET /auth/me': { data: sampleUser }, 'GET /surplus': paginated([lot()]) },
        });

        const search = document.querySelector('#search');
        search.value = 'tomate';
        search.dispatchEvent(new window.Event('input'));
        await new Promise((resolve) => setTimeout(resolve, 400));
        await flush();

        expect(calls.filter((call) => call.path === '/surplus').at(-1).query).toContain('search=tomate');
    });

    it('paginação usa o meta da resposta e pede a página seguinte', async () => {
        const { calls } = await bootApp({
            hash: '#/catalogo',
            session: { user: { ...sampleUser, roles: ['buyer'] } },
            routes: {
                'GET /auth/me': { data: sampleUser },
                'GET /surplus': paginated([lot()], { current_page: 1, last_page: 3, total: 30 }),
            },
        });

        expect(document.querySelector('.pagination').textContent).toContain('Página 1 de 3');
        await click('[data-page="2"]');

        expect(calls.filter((call) => call.path === '/surplus').at(-1).query).toContain('page=2');
    });

    it('não inventa lotes quando a API recusa e oferece nova tentativa', async () => {
        await bootApp({
            hash: '#/catalogo',
            session: { user: { ...sampleUser, roles: ['buyer'] } },
            routes: {
                'GET /auth/me': { data: sampleUser },
                'GET /surplus': { status: 503, body: { message: 'Serviço indisponível.' } },
            },
        });

        const banner = document.querySelector('.catalog-alert');
        expect(banner).not.toBeNull();
        expect(banner.textContent).toContain('Não foi possível carregar');
        expect(document.querySelector('[data-retry-catalog]')).not.toBeNull();
        expect(document.querySelectorAll('[data-lot-card]')).toHaveLength(0);
    });

    it('visitante anônimo não vê dados fictícios e recebe o convite para entrar', async () => {
        const { calls } = await bootApp({ hash: '#/catalogo' });

        expect(calls.filter((call) => call.path === '/surplus')).toHaveLength(0);
        expect(document.querySelector('.catalog-alert').textContent).toContain('excedentes reais');
        expect(document.querySelector('.catalog-alert [data-open-auth]')).not.toBeNull();
    });

    it('não permite comprar quando os dados reais não foram carregados', async () => {
        const { calls } = await bootApp({
            hash: '#/catalogo',
            session: { user: { ...sampleUser, roles: ['buyer'] } },
            routes: { 'GET /auth/me': { data: sampleUser }, 'GET /surplus': { status: 500, body: {} } },
        });

        expect(calls.some((call) => call.path.includes('buy-now'))).toBe(false);
        expect(document.querySelectorAll('[data-lot-card]')).toHaveLength(0);
        expect(document.querySelector('[data-retry-catalog]')).not.toBeNull();
    });

    it('comprar com dados reais chama buy-now e vai para o acompanhamento', async () => {
        const { calls } = await bootApp({
            hash: '#/catalogo',
            session: { user: { ...sampleUser, roles: ['buyer'] } },
            routes: {
                'GET /auth/me': { data: { ...sampleUser, roles: ['buyer'] } },
                'GET /surplus': paginated([lot()]),
                'POST /surplus/1/buy-now': { status: 201, body: { data: { id: 42, status: 'reserved' } } },
                'GET /trades': { data: [] },
            },
        });

        await click('[data-buy="1"]');

        expect(calls.some((call) => call.method === 'POST' && call.path === '/surplus/1/buy-now')).toBe(true);
        expect(window.location.hash).toBe('#/acompanhamento?id=42');
    });

    it('estado vazio explica a busca que não encontrou nada', async () => {
        await bootApp({
            hash: '#/catalogo',
            session: { user: { ...sampleUser, roles: ['buyer'] } },
            routes: { 'GET /auth/me': { data: sampleUser }, 'GET /surplus': paginated([]) },
        });

        const search = document.querySelector('#search');
        search.value = 'inexistente';
        search.dispatchEvent(new window.Event('input'));
        await new Promise((resolve) => setTimeout(resolve, 400));
        await flush();

        expect(document.querySelector('.empty-state').textContent).toContain('inexistente');
    });

    it('navegar entre telas não acumula listeners no botão do header', async () => {
        await bootApp({
            hash: '#/',
            session: { user: sampleUser },
            routes: { 'GET /auth/me': { data: sampleUser }, 'GET /surplus': paginated([lot()]), 'GET /trades': { data: [] } },
        });

        await navigate('#/catalogo');
        await navigate('#/');
        await navigate('#/doacoes');
        await navigate('#/');

        document.querySelector('.wallet-button').click();
        await flush();

        expect(window.location.hash).toBe('#/dashboard');
    });
});
