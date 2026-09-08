import { describe, expect, it } from 'vitest';
import { bootApp, click, flush, sampleUser, trade } from './helpers/boot.js';

const producerDashboard = {
    role: 'producer',
    summary: {
        active_surplus: 6,
        commercial_operations: 2,
        donation_operations: 1,
        recovered_revenue: '18420.000000',
        ratings: { average: 4.9, count: 37 },
    },
    quantities: { sold: { kg: '5000.000' }, donated: { kg: '3700.000' }, total_destined: { kg: '8700.000', t: '1.200' } },
    trade_statuses: { reserved: 3, funded: 5, completed: 7 },
};

describe('painel do ator', () => {
    it('mostra os números vindos da API, não valores fixos', async () => {
        await bootApp({
            hash: '#/dashboard',
            session: { user: sampleUser },
            routes: {
                'GET /auth/me': { data: sampleUser },
                'GET /dashboard/producer': { data: producerDashboard },
                'GET /trades': { data: [] },
            },
        });

        const cards = [...document.querySelectorAll('.metric-card')].map((card) => card.textContent);
        expect(cards.join(' ')).toContain('6');
        expect(cards.join(' ')).toContain('18.420,00 FRUSD');
        expect(cards.join(' ')).toContain('8.700 kg · 1,2 t');
        expect(cards.join(' ')).toContain('4,9 / 5');
        expect(cards.join(' ')).toContain('37 avaliações');
        expect(document.body.textContent).not.toContain('+12% no mês');
    });

    it('sem avaliações não inventa nota', async () => {
        await bootApp({
            hash: '#/dashboard',
            session: { user: sampleUser },
            routes: {
                'GET /auth/me': { data: sampleUser },
                'GET /dashboard/producer': { data: { ...producerDashboard, summary: { ...producerDashboard.summary, ratings: { average: null, count: 0 } } } },
                'GET /trades': { data: [] },
            },
        });

        expect(document.body.textContent).toContain('Sem avaliações');
        expect(document.body.textContent).not.toContain('/ 5');
    });

    it('as barras de etapa vêm de trade_statuses e a maior fica em 100%', async () => {
        await bootApp({
            hash: '#/dashboard',
            session: { user: sampleUser },
            routes: {
                'GET /auth/me': { data: sampleUser },
                'GET /dashboard/producer': { data: producerDashboard },
                'GET /trades': { data: [] },
            },
        });

        const rows = [...document.querySelectorAll('.status-row')];
        expect(rows).toHaveLength(3);
        expect(rows.map((row) => row.textContent)).toEqual(expect.arrayContaining([expect.stringContaining('Concluído')]));
        expect(rows.find((row) => row.textContent.includes('Concluído')).querySelector('i').style.width).toBe('100%');
    });

    it('pede o painel do papel do usuário e não oferece troca de papel', async () => {
        const { calls } = await bootApp({
            hash: '#/dashboard',
            session: { user: { ...sampleUser, roles: ['ngo'] } },
            routes: {
                'GET /auth/me': { data: { ...sampleUser, roles: ['ngo'] } },
                'GET /dashboard/ngo': { data: { role: 'ngo', summary: { completed_donations: 4, shipping_spend: '0', rescue_proofs: 2, ratings: { average: null, count: 0 } }, quantities: { rescued: {} }, trade_statuses: {} } },
                'GET /trades': { data: [] },
            },
        });

        expect(calls.some((call) => call.path === '/dashboard/ngo')).toBe(true);
        expect(document.querySelectorAll('[data-role]')).toHaveLength(0);
    });

    it('operações recentes vêm de /trades e linkam para o acompanhamento', async () => {
        await bootApp({
            hash: '#/dashboard',
            session: { user: sampleUser },
            routes: {
                'GET /auth/me': { data: sampleUser },
                'GET /dashboard/producer': { data: producerDashboard },
                'GET /trades': { data: [trade({ id: 21, status: 'funded' })] },
            },
        });

        const item = document.querySelector('[data-recent] .activity-item');
        expect(item.textContent).toContain('Tomate italiano');
        expect(item.getAttribute('href')).toBe('#/acompanhamento?id=21');
    });

    it('falha do painel não derruba a tela', async () => {
        await bootApp({
            hash: '#/dashboard',
            session: { user: sampleUser },
            routes: {
                'GET /auth/me': { data: sampleUser },
                'GET /dashboard/producer': { status: 403, body: { message: 'Este dashboard não está disponível para o seu papel.' } },
                'GET /trades': { data: [] },
            },
        });

        expect(document.querySelector('[data-metrics]').textContent).toContain('não está disponível');
    });

    it('administrador é levado para o painel do protocolo', async () => {
        await bootApp({
            hash: '#/dashboard',
            session: { user: { ...sampleUser, roles: ['admin'] } },
            routes: {
                'GET /auth/me': { data: { ...sampleUser, roles: ['admin'] } },
                'GET /admin/dashboard': { data: { summary: { users: { total: 4, producers: 1, buyers: 1, carriers: 1, ngos: 1 }, open_surplus: 6, commercial_operations: 0, donation_operations: 0, producer_revenue_recovered: '0', protocol_fees: '0', carrier_freight_paid: '0' }, trade_statuses: {} } },
                'GET /admin/impact': { data: { quantities: { total_destined: {}, sold: {}, donated: {} }, financial: { producer_revenue_recovered: '0', protocol_fees: '0', freight_paid: '0' }, operations: {}, participants: { producers: 0, commercial_buyers: 0, ngos: 0, carriers: 0 } } },
            },
        });

        expect(window.location.hash).toContain('#/admin');
    });
});

describe('administração', () => {
    const adminUser = { ...sampleUser, roles: ['admin'] };
    const overview = {
        'GET /admin/dashboard': { data: { summary: { users: { total: 4, producers: 1, buyers: 1, carriers: 1, ngos: 1 }, open_surplus: 6, commercial_operations: 2, donation_operations: 1, producer_revenue_recovered: '1000.000000', protocol_fees: '20.000000', carrier_freight_paid: '50.000000' }, trade_statuses: { reserved: 2 } } },
        'GET /admin/impact': { data: { quantities: { total_destined: { kg: '8700.000' }, sold: {}, donated: {} }, financial: { producer_revenue_recovered: '1000', protocol_fees: '20', freight_paid: '50' }, operations: {}, participants: { producers: 1, commercial_buyers: 1, ngos: 1, carriers: 1 } } },
    };

    it('nega acesso a quem não é admin', async () => {
        await bootApp({
            hash: '#/admin',
            session: { user: sampleUser },
            routes: { 'GET /auth/me': { data: sampleUser } },
        });

        expect(document.body.textContent).toContain('Acesso restrito');
    });

    it('visão geral combina dashboard e impacto', async () => {
        await bootApp({ hash: '#/admin', session: { user: adminUser }, routes: { 'GET /auth/me': { data: adminUser }, ...overview } });

        expect(document.body.textContent).toContain('8.700 kg');
        expect(document.body.textContent).toContain('1.000,00 FRUSD');
        expect(document.querySelectorAll('.status-row')).toHaveLength(1);
    });

    it('bloqueia e reativa usuários pelo status', async () => {
        const { calls } = await bootApp({
            hash: '#/admin?tab=usuarios',
            session: { user: adminUser },
            routes: {
                'GET /auth/me': { data: adminUser },
                ...overview,
                'GET /admin/users': { data: [{ id: 8, name: 'Mercado Central', email: 'comprador@foodrescue.test', status: 'active', roles: ['buyer'] }] },
                'PATCH /admin/users/8/status': { data: { id: 8, status: 'blocked' } },
            },
        });

        expect(document.querySelector('[data-user-status="8"]').textContent).toBe('Bloquear');
        await click('[data-user-status="8"]');

        expect(calls.find((call) => call.method === 'PATCH').body).toEqual({ status: 'blocked' });
    });

    it('cadastra produto no catálogo', async () => {
        const { calls } = await bootApp({
            hash: '#/admin?tab=catalogo',
            session: { user: adminUser },
            routes: {
                'GET /auth/me': { data: adminUser },
                ...overview,
                'GET /admin/catalog/products': { data: [{ id: 3, name: 'Tomate', active: true }] },
                'GET /admin/catalog/quality-grades': { data: [] },
                'POST /admin/catalog/products': { status: 201, body: { data: { id: 9 } } },
            },
        });

        const form = document.querySelector('[data-new-product]');
        form.querySelector('[name="name"]').value = 'Alface';
        form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
        await flush();

        expect(calls.find((call) => call.method === 'POST' && call.path === '/admin/catalog/products').body).toEqual({ name: 'Alface', active: true });
    });

    it('salva os prazos como números', async () => {
        const { calls } = await bootApp({
            hash: '#/admin?tab=ajustes',
            session: { user: adminUser },
            routes: {
                'GET /auth/me': { data: adminUser },
                ...overview,
                'GET /admin/settings/timeouts': { data: { shipping_quotation_timeout_minutes: 240, payment_timeout_minutes: 15 } },
                'PATCH /admin/settings/timeouts': { data: { shipping_quotation_timeout_minutes: 300, payment_timeout_minutes: 15 } },
            },
        });

        const form = document.querySelector('[data-settings-form]');
        form.querySelector('[name="shipping_quotation_timeout_minutes"]').value = '300';
        form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
        await flush();

        expect(calls.find((call) => call.method === 'PATCH').body).toEqual({
            shipping_quotation_timeout_minutes: 300,
            payment_timeout_minutes: 15,
        });
    });

    it('mostra o ProtocolConfig já confirmado na aba Solana', async () => {
        await bootApp({
            hash: '#/admin?tab=solana',
            session: { user: adminUser },
            routes: {
                'GET /auth/me': { data: adminUser },
                'GET /blockchain/protocol': {
                    data: {
                        cluster: 'devnet',
                        program_id: '7VYeepULRV6SpzugLquhizUf3iNH3jtLqEJ5RqQqPboi',
                        mint: '9tVPExJFkBU3yLgyo8fVzFVQj2t2boEESSpmoYikfxRr',
                        treasury_wallet: 'G7QtKUSLYiYcdtzUAUdyyxg7jjuGoTrqePspnmE49mbv',
                        config_pda: '5wbZZiSzAbn7g8obEUJCQrdZTC7y6UrKuazvY7CVcu7T',
                        confirmed_at: '2026-09-08T10:00:00.000000Z',
                    },
                },
            },
        });

        expect(document.body.textContent).toContain('ProtocolConfig inicializado');
        expect(document.body.textContent).toContain('7VYeepULRV6SpzugLquhizUf3iNH3jtLqEJ5RqQqPboi');
        expect(document.querySelector('[data-protocol-start]')).toBeNull();
    });
});

describe('telas públicas', () => {
    it('doações não mostram mais a prova inventada', async () => {
        await bootApp({ hash: '#/doacoes' });

        expect(document.body.textContent).not.toContain('FR-1028');
        expect(document.body.textContent).not.toContain('Instituto Mesa Aberta');
        expect(document.body.textContent).toContain('Prova on-chain de destinação');
    });

    it('doações mostram o Proof of Rescue real quando existe', async () => {
        const ngo = { ...sampleUser, roles: ['ngo'] };
        await bootApp({
            hash: '#/doacoes',
            session: { user: ngo },
            routes: {
                'GET /auth/me': { data: ngo },
                'GET /trades': {
                    data: [trade({
                        id: 31,
                        is_donation: true,
                        status: 'completed',
                        rescue_proof: { confirmed_at: '2026-09-06T12:00:00.000000Z', proof_pda: 'FyEbrkBEeoyNF3HyyaMbc51cKNL1X1uoysb2qdfoy3Qe' },
                    })],
                },
            },
        });

        expect(document.querySelector('.proof-card').textContent).toContain('Resgate #31');
        expect(document.querySelector('.proof-card').textContent).toContain('680.000 kg');
    });

    it('rede usa o ProtocolConfig confirmado quando disponível', async () => {
        await bootApp({
            hash: '#/rede',
            session: { user: sampleUser },
            routes: {
                'GET /auth/me': { data: sampleUser },
                'GET /blockchain/protocol': {
                    data: {
                        cluster: 'devnet',
                        program_id: 'Ex6CN32gBUH2JALUwbqyn8ZMwWmBNrrHa4sjDNMAm5sd',
                        treasury_wallet: 'G7QtKUSLYiYcdtzUAUdyyxg7jjuGoTrqePspnmE49mbv',
                        mint: '9tVPExJFkBU3yLgyo8fVzFVQj2t2boEESSpmoYikfxRr',
                        config_pda: '5wbZZiSzAbn7g8obEUJCQrdZTC7y6UrKuazvY7CVcu7T',
                        version: 1,
                        confirmed_at: '2026-09-01T10:00:00.000000Z',
                    },
                },
            },
        });

        expect(document.querySelectorAll('.chain-card')).toHaveLength(4);
        expect(document.body.textContent).toContain('Parâmetros de confiança');
        expect(document.body.textContent).toContain('Última verificação pública');
    });

    it('rede explica quando o protocolo ainda não foi inicializado', async () => {
        await bootApp({
            hash: '#/rede',
            session: { user: sampleUser },
            routes: { 'GET /auth/me': { data: sampleUser }, 'GET /blockchain/protocol': { status: 503, body: { message: 'não inicializado' } } },
        });

        expect(document.querySelectorAll('.chain-card')).toHaveLength(2);
        expect(document.body.textContent).toContain('A configuração pública ainda está sendo preparada');
    });
});
