import { describe, expect, it } from 'vitest';
import { bootApp, flush, navigate, paginated, sampleUser } from './helpers/boot.js';

const dashboardVazio = { role: 'producer', summary: { active_surplus: 0, commercial_operations: 0, donation_operations: 0, recovered_revenue: '0', ratings: { average: null, count: 0 } }, quantities: { total_destined: {} }, trade_statuses: {} };

const rotas = {
    'GET /surplus': paginated([]),
    'GET /trades': { data: [] },
    'GET /shipping-requests': { data: [] },
    'GET /catalog/products': { data: [] },
    'GET /catalog/quality-grades': { data: [] },
    'GET /dashboard/producer': { data: dashboardVazio },
    'GET /dashboard/buyer': { data: { ...dashboardVazio, role: 'buyer', summary: { completed_purchases: 0, total_spend: '0', shipping_spend: '0', ratings: { count: 0 } }, quantities: { purchased: {} } } },
    'GET /dashboard/carrier': { data: { ...dashboardVazio, role: 'carrier', summary: { completed_deliveries: 0, freight_revenue: '0', ratings: { count: 0 } }, quantities: { transported: {} } } },
    'GET /dashboard/ngo': { data: { ...dashboardVazio, role: 'ngo', summary: { completed_donations: 0, shipping_spend: '0', rescue_proofs: 0, ratings: { count: 0 } }, quantities: { rescued: {} } } },
    'GET /admin/dashboard': { data: { summary: { users: { total: 0, producers: 0, buyers: 0, carriers: 0, ngos: 0 }, open_surplus: 0, commercial_operations: 0, donation_operations: 0, producer_revenue_recovered: '0', protocol_fees: '0', carrier_freight_paid: '0' }, trade_statuses: {} } },
    'GET /admin/impact': { data: { quantities: { total_destined: {}, sold: {}, donated: {} }, financial: { producer_revenue_recovered: '0', protocol_fees: '0', freight_paid: '0' }, operations: {}, participants: { producers: 0, commercial_buyers: 0, ngos: 0, carriers: 0 } } },
};

function comPapel(papel) {
    const user = { ...sampleUser, roles: [papel] };

    return bootApp({ hash: '#/', session: { user }, routes: { 'GET /auth/me': { data: user }, ...rotas } });
}

function menu() {
    return [...document.querySelectorAll('[data-nav-desktop] a')].map((a) => a.textContent);
}

function destinos() {
    return [...document.querySelectorAll('[data-nav-desktop] a')].map((a) => a.getAttribute('href'));
}

describe('menu por perfil', () => {
    it('visitante vê só a vitrine pública', async () => {
        await bootApp({ hash: '#/', routes: rotas });

        expect(menu()).toEqual(['Excedentes', 'Doações', 'Solana']);
        expect(destinos()).not.toContain('#/publicar');
        expect(destinos()).not.toContain('#/admin?tab=visao');
    });

    it('produtor começa pelos próprios lotes e vê publicar', async () => {
        await comPapel('producer');

        expect(menu()).toEqual(['Meus lotes', 'Publicar', 'Minhas vendas', 'Painel']);
        expect(destinos()).not.toContain('#/fretes');
    });

    it('comprador vê o catálogo e as compras, não vê publicar', async () => {
        await comPapel('buyer');

        expect(menu()).toEqual(['Excedentes', 'Minhas compras', 'Painel']);
        expect(destinos()).not.toContain('#/publicar');
        expect(destinos()).not.toContain('#/meus-lotes');
    });

    it('transportadora vê rotas e fretes, não vê o catálogo de excedentes', async () => {
        await comPapel('carrier');

        expect(menu()).toEqual(['Rotas abertas', 'Meus fretes', 'Painel']);
        expect(destinos()).not.toContain('#/catalogo');
    });

    it('ONG vê lotes para doação e o Proof of Rescue', async () => {
        await comPapel('ngo');

        expect(menu()).toEqual(['Lotes para doação', 'Minhas doações', 'Proof of Rescue', 'Painel']);
    });

    it('administrador vê as abas do protocolo e nada do marketplace', async () => {
        await comPapel('admin');

        expect(menu()).toEqual(['Visão geral', 'Usuários', 'Catálogo', 'Prazos']);
        expect(destinos()).not.toContain('#/catalogo');
        expect(destinos()).not.toContain('#/acompanhamento');
    });

    it('o menu troca sozinho ao entrar e ao sair', async () => {
        await bootApp({ hash: '#/', routes: rotas });
        expect(menu()).toEqual(['Excedentes', 'Doações', 'Solana']);

        const user = { ...sampleUser, roles: ['carrier'] };
        await bootApp({ hash: '#/', session: { user }, routes: { 'GET /auth/me': { data: user }, ...rotas } });
        expect(menu()).toEqual(['Rotas abertas', 'Meus fretes', 'Painel']);

        document.querySelector('[data-logout]').click();
        await flush();
        expect(menu()).toEqual(['Excedentes', 'Doações', 'Solana']);
    });

    it('marca o item da tela atual, inclusive a aba do administrador', async () => {
        await comPapel('buyer');
        await navigate('#/catalogo');

        expect(document.querySelector('[data-nav-desktop] a.active').textContent).toBe('Excedentes');

        await comPapel('admin');
        await navigate('#/admin?tab=usuarios');

        expect(document.querySelector('[data-nav-desktop] a.active').textContent).toBe('Usuários');
    });

    it('o menu móvel separa a área de conta', async () => {
        await comPapel('producer');
        const movel = document.querySelector('[data-nav-mobile]');

        expect(movel.textContent).toContain('Conta');
        expect([...movel.querySelectorAll('a')].map((a) => a.getAttribute('href')))
            .toEqual(expect.arrayContaining(['#/perfil', '#/reputacao', '#/rede']));
    });

    it('a barra lateral do painel repete o menu do papel', async () => {
        await comPapel('carrier');
        await navigate('#/dashboard');

        const lateral = [...document.querySelectorAll('.sidebar nav a')].map((a) => a.getAttribute('href'));
        expect(lateral).toEqual(expect.arrayContaining(['#/fretes', '#/acompanhamento', '#/perfil']));
        expect(lateral).not.toContain('#/meus-lotes');
    });
});

describe('catálogo por perfil', () => {
    it('a ONG entra já filtrando lotes que aceitam doação', async () => {
        const user = { ...sampleUser, roles: ['ngo'] };
        const { calls } = await bootApp({
            hash: '#/catalogo',
            session: { user },
            routes: { 'GET /auth/me': { data: user }, ...rotas },
        });

        expect(calls.find((call) => call.path === '/surplus').query).toContain('donation_eligible=1');
        expect(document.querySelector('#donation-filter').value).toBe('1');
    });

    it('o comprador entra sem filtro de finalidade', async () => {
        const user = { ...sampleUser, roles: ['buyer'] };
        const { calls } = await bootApp({
            hash: '#/catalogo',
            session: { user },
            routes: { 'GET /auth/me': { data: user }, ...rotas },
        });

        expect(calls.find((call) => call.path === '/surplus').query).not.toContain('donation_eligible');
    });
});
