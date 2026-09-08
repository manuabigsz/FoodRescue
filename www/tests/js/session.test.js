import { describe, expect, it } from 'vitest';
import { bootApp, click, flush, navigate, paginated, sampleUser } from './helpers/boot.js';

describe('sessão', () => {
    it('revalida o token guardado com /auth/me no boot', async () => {
        const { calls } = await bootApp({
            hash: '#/',
            session: { user: sampleUser },
            routes: { 'GET /auth/me': { data: { ...sampleUser, name: 'Nome Atualizado' } } },
        });

        expect(calls.some((call) => call.path === '/auth/me')).toBe(true);
        expect(JSON.parse(sessionStorage.getItem('foodrescue_user')).name).toBe('Nome Atualizado');
    });

    it('descarta o token expirado sem chamar a API', async () => {
        const { calls } = await bootApp({
            hash: '#/',
            session: { user: sampleUser, expiresAt: new Date(Date.now() - 60000).toISOString() },
            routes: { 'GET /auth/me': { data: sampleUser } },
        });

        expect(calls.some((call) => call.path === '/auth/me')).toBe(false);
        expect(sessionStorage.getItem('foodrescue_token')).toBeNull();
    });

    it('um 401 em qualquer chamada encerra a sessão local', async () => {
        await bootApp({
            hash: '#/catalogo',
            session: { user: sampleUser },
            routes: {
                'GET /auth/me': { data: sampleUser },
                'GET /surplus': { status: 401, body: { message: 'Unauthenticated.' } },
            },
        });

        expect(sessionStorage.getItem('foodrescue_token')).toBeNull();
        expect(document.querySelector('.wallet-button').textContent).toBe('Conectar carteira');
    });

    it('login guarda token, usuário e validade e leva ao painel', async () => {
        const { calls } = await bootApp({
            hash: '#/',
            routes: {
                'POST /auth/login': {
                    data: {
                        user: sampleUser,
                        token: 'novo-token',
                        token_type: 'Bearer',
                        expires_at: '2030-01-01T00:00:00.000000Z',
                    },
                },
                'GET /dashboard/producer': { data: { role: 'producer', summary: { active_surplus: 0, commercial_operations: 0, donation_operations: 0, recovered_revenue: '0', ratings: { average: null, count: 0 } }, quantities: { total_destined: {} }, trade_statuses: {} } },
                'GET /trades': { data: [] },
            },
        });

        await click('.wallet-button');
        const form = document.querySelector('[data-login-form]');
        form.querySelector('[name="email"]').value = 'produtor@foodrescue.test';
        form.querySelector('[name="password"]').value = 'uma-senha-bem-longa';
        form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
        await flush();

        expect(calls.find((call) => call.path === '/auth/login').body.device_name).toBe('foodrescue-web');
        expect(sessionStorage.getItem('foodrescue_token')).toBe('novo-token');
        expect(sessionStorage.getItem('foodrescue_token_expires_at')).toBe('2030-01-01T00:00:00.000000Z');
        expect(window.location.hash).toBe('#/dashboard');
        expect(document.querySelector('[data-logout]').hidden).toBe(false);
    });

    it('logout revoga o token no servidor e limpa o armazenamento', async () => {
        const { calls } = await bootApp({
            hash: '#/',
            session: { user: sampleUser },
            routes: { 'GET /auth/me': { data: sampleUser }, 'POST /auth/logout': { status: 204 } },
        });

        await click('[data-logout]');

        expect(calls.some((call) => call.method === 'POST' && call.path === '/auth/logout')).toBe(true);
        expect(sessionStorage.getItem('foodrescue_token')).toBeNull();
        expect(document.querySelector('[data-logout]').hidden).toBe(true);
    });

    it('logout limpa a sessão mesmo se a revogação remota falhar', async () => {
        await bootApp({
            hash: '#/',
            session: { user: sampleUser },
            routes: { 'GET /auth/me': { data: sampleUser }, 'POST /auth/logout': { status: 500, body: {} } },
        });

        await click('[data-logout]');

        expect(sessionStorage.getItem('foodrescue_token')).toBeNull();
    });

    it('o botão do header leva ao painel quando já existe sessão', async () => {
        await bootApp({
            hash: '#/',
            session: { user: sampleUser },
            routes: { 'GET /auth/me': { data: sampleUser }, 'GET /dashboard/producer': { status: 500, body: {} }, 'GET /trades': { data: [] } },
        });

        await click('.wallet-button');

        expect(window.location.hash).toBe('#/dashboard');
        expect(document.querySelector('[data-auth-modal]').hidden).toBe(true);
    });

    it('o header mostra a carteira encurtada quando há sessão', async () => {
        await bootApp({
            hash: '#/',
            session: { user: sampleUser },
            routes: { 'GET /auth/me': { data: sampleUser } },
        });

        expect(document.querySelector('.wallet-button').textContent).toContain('…');
        expect(document.querySelector('.wallet-button').textContent).not.toBe('Conectar carteira');
    });
});

describe('roteamento', () => {
    const routes = {
        'GET /auth/me': { data: sampleUser },
        'GET /surplus': paginated([]),
        'GET /trades': { data: [] },
        'GET /catalog/products': { data: [] },
        'GET /catalog/quality-grades': { data: [] },
        'GET /dashboard/producer': { status: 500, body: {} },
        'GET /users/7/reputation': { data: { user_id: 7, average: null, count: 0 } },
        'GET /users/7/ratings': { data: [] },
        'GET /blockchain/protocol': { status: 404, body: {} },
    };

    const screens = [
        ['#/', 'Alimento bom não vira'],
        ['#/catalogo', 'Excedentes disponíveis'],
        ['#/acompanhamento', 'Acompanhe cada etapa'],
        ['#/doacoes', 'Resgatar é dar destino'],
        ['#/rede', 'Infraestrutura Solana'],
        ['#/publicar', 'Publicar excedente'],
        ['#/meus-lotes', 'Meus lotes'],
        ['#/perfil', 'Perfil'],
        ['#/reputacao', 'Avaliações recebidas'],
        ['#/fretes', 'Rotas abertas'],
        ['#/rota-inexistente', 'Alimento bom não vira'],
    ];

    it.each(screens)('%s renderiza sem erro', async (hash, expected) => {
        await bootApp({ hash: '#/', session: { user: sampleUser }, routes });
        await navigate(hash);

        expect(document.querySelector('#main-content').textContent).toContain(expected);
    });
});
