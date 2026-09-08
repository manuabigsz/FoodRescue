import { describe, expect, it, vi } from 'vitest';
import { bootApp, click, flush, sampleUser } from './helpers/boot.js';

function submit(selector) {
    document.querySelector(selector).dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));

    return flush();
}

describe('cotação de fretes', () => {
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

    function boot(user, routes = {}) {
        return bootApp({
            hash: '#/fretes',
            session: { user },
            routes: { 'GET /auth/me': { data: user }, 'GET /shipping-requests': { data: [request] }, ...routes },
        });
    }

    it('só transportadora vê a lista', async () => {
        const { calls } = await boot({ ...sampleUser, roles: ['buyer'] });

        expect(document.body.textContent).toContain('Área da transportadora');
        expect(calls.some((call) => call.path === '/shipping-requests')).toBe(false);
    });

    it('mostra a rota, a carga e o prazo de cotação', async () => {
        await boot(carrier);

        expect(document.querySelector('.freight-card').textContent).toContain('Mogi das Cruzes → São Paulo');
        expect(document.querySelector('.freight-card').textContent).toContain('680 kg');
        expect(document.querySelector('.freight-card').textContent).toContain('operação #10');
    });

    it('envia a cotação com as datas em ISO', async () => {
        const { calls } = await boot(carrier, {
            'POST /shipping-requests/5/offers': { status: 201, body: { data: { id: 9 } } },
        });

        const form = document.querySelector('[data-freight-form="5"]');
        form.querySelector('[name="amount"]').value = 'US$ 480.00';
        form.querySelector('[name="pickup_at"]').value = '2030-01-01T08:00';
        form.querySelector('[name="estimated_delivery_at"]').value = '2030-01-01T18:00';
        await submit('[data-freight-form="5"]');

        const body = calls.find((call) => call.path === '/shipping-requests/5/offers').body;
        expect(body.amount).toBe('480.00');
        expect(body.pickup_at).toMatch(/^2030-01-01T\d\d:00:00\.000Z$/);
        expect(new Date(body.estimated_delivery_at) > new Date(body.pickup_at)).toBe(true);
    });

    it('erro da API fica no formulário e o botão volta a funcionar', async () => {
        await boot(carrier, {
            'POST /shipping-requests/5/offers': { status: 422, body: { message: 'Prazo de cotação encerrado.' } },
        });

        const form = document.querySelector('[data-freight-form="5"]');
        form.querySelector('[name="amount"]').value = 'US$ 480.00';
        form.querySelector('[name="pickup_at"]').value = '2030-01-01T08:00';
        form.querySelector('[name="estimated_delivery_at"]').value = '2030-01-01T18:00';
        await submit('[data-freight-form="5"]');

        expect(form.querySelector('[data-form-message]').textContent).toBe('Prazo de cotação encerrado.');
        expect(form.querySelector('[type="submit"]').disabled).toBe(false);
    });

    it('lista vazia explica em vez de ficar em branco', async () => {
        await boot(carrier, { 'GET /shipping-requests': { data: [] } });

        expect(document.body.textContent).toContain('Nenhuma rota aberta');
    });
});

describe('perfil', () => {
    function boot(routes = {}, user = sampleUser) {
        return bootApp({
            hash: '#/perfil',
            session: { user },
            routes: { 'GET /auth/me': { data: user }, ...routes },
        });
    }

    it('envia só o campo alterado e não exige senha para trocar o nome', async () => {
        const { calls } = await boot({ 'PATCH /auth/me': { data: { ...sampleUser, name: 'Novo Nome' } } });

        document.querySelector('[name="name"]').value = 'Novo Nome';
        await submit('[data-account-form]');

        expect(calls.find((call) => call.method === 'PATCH').body).toEqual({ name: 'Novo Nome' });
    });

    it('trocar o e-mail leva junto a senha atual', async () => {
        const { calls } = await boot({ 'PATCH /auth/me': { data: sampleUser } });

        document.querySelector('[name="email"]').value = 'outro@foodrescue.test';
        document.querySelector('[data-account-form] [name="current_password"]').value = 'senha-atual-longa';
        await submit('[data-account-form]');

        expect(calls.find((call) => call.method === 'PATCH').body).toEqual({
            email: 'outro@foodrescue.test',
            current_password: 'senha-atual-longa',
        });
    });

    it('sem mudança nenhuma não chama a API', async () => {
        const { calls } = await boot();

        await submit('[data-account-form]');

        expect(calls.some((call) => call.method === 'PATCH')).toBe(false);
        expect(document.querySelector('[data-account-form] [data-form-message]').textContent).toBe('Nada foi alterado.');
    });

    it('trocar a senha encerra a sessão local', async () => {
        const { calls } = await boot({ 'PUT /auth/password': { status: 204 } });

        const form = document.querySelector('[data-password-form]');
        form.querySelector('[name="current_password"]').value = 'senha-atual-longa';
        form.querySelector('[name="password"]').value = 'uma-nova-senha-bem-longa';
        form.querySelector('[name="password_confirmation"]').value = 'uma-nova-senha-bem-longa';
        await submit('[data-password-form]');

        expect(calls.some((call) => call.method === 'PUT' && call.path === '/auth/password')).toBe(true);
        expect(sessionStorage.getItem('foodrescue_token')).toBeNull();
    });

    it('encerrar todas as sessões revoga e limpa o armazenamento', async () => {
        const { calls } = await boot({ 'POST /auth/logout-all': { status: 204 } });

        await click('[data-logout-all]');

        expect(calls.some((call) => call.method === 'POST' && call.path === '/auth/logout-all')).toBe(true);
        expect(sessionStorage.getItem('foodrescue_token')).toBeNull();
        expect(window.location.hash).toBe('#/');
    });

    it('avisa quando não há carteira compatível no navegador', async () => {
        await boot();

        await click('[data-change-wallet]');

        expect(document.querySelector('[data-wallet-message]').textContent).toContain('Nenhuma carteira Solana compatível');
    });

    it('troca de carteira faz challenge, assina e verifica', async () => {
        const { calls } = await boot({
            'POST /auth/wallet/change/challenge': { status: 201, body: { data: { id: 3, message: 'assine isto' } } },
            'POST /auth/wallet/change/verify': { data: sampleUser },
        });

        const signature = new Uint8Array([1, 2, 3]);
        window.solana = {
            connect: vi.fn().mockResolvedValue({ publicKey: { toString: () => 'FyEbrkBEeoyNF3HyyaMbc51cKNL1X1uoysb2qdfoy3Qe' } }),
            signMessage: vi.fn().mockResolvedValue({ signature }),
        };

        await click('[data-change-wallet]');

        expect(calls.find((call) => call.path === '/auth/wallet/change/challenge').body)
            .toEqual({ wallet_address: 'FyEbrkBEeoyNF3HyyaMbc51cKNL1X1uoysb2qdfoy3Qe' });
        expect(calls.find((call) => call.path === '/auth/wallet/change/verify').body)
            .toEqual({ challenge_id: 3, signature: 'AQID' });
        delete window.solana;
    });
});

describe('cadastro com carteira', () => {
    async function openRegister(routes = {}) {
        const result = await bootApp({ hash: '#/', routes });
        await click('.wallet-button');
        document.querySelector('[data-auth-tab="register"]').click();
        await flush();

        return result;
    }

    it('exige conectar a carteira antes de enviar o cadastro', async () => {
        const { calls } = await openRegister();

        await submit('[data-register-form]');

        expect(document.querySelector('[data-register-form] [data-form-message]').textContent)
            .toContain('Conecte sua carteira');
        expect(calls.some((call) => call.path === '/auth/register')).toBe(false);
    });

    it('indica que a carteira é obrigatória para todos os atores públicos', async () => {
        const { calls } = await openRegister();
        const role = document.querySelector('#reg-role');
        const card = document.querySelector('[data-wallet-required]');

        expect(card.textContent).toContain('Obrigatória para criar a conta');

        for (const value of ['producer', 'buyer', 'carrier', 'ngo']) {
            role.value = value;
            role.dispatchEvent(new window.Event('change'));
            await submit('[data-register-form]');
            expect(document.querySelector('[data-register-form] [data-form-message]').textContent)
                .toContain('Conecte sua carteira');
        }

        expect(calls.some((call) => call.path === '/auth/wallet/challenge')).toBe(false);
        expect(calls.some((call) => call.path === '/auth/register')).toBe(false);
    });

    it('troca os campos do perfil conforme o papel escolhido', async () => {
        await openRegister();

        expect(document.querySelector('[name="producer_type"]')).not.toBeNull();

        const role = document.querySelector('#reg-role');
        role.value = 'ngo';
        role.dispatchEvent(new window.Event('change'));
        await flush();

        expect(document.querySelector('[name="producer_type"]')).toBeNull();
        expect(document.querySelector('[name="registration_number"]')).not.toBeNull();
        expect(document.querySelector('[name="document_number"]').required).toBe(false);
    });

    it('usa estados brasileiros e aplica máscara no telefone', async () => {
        await openRegister();

        const stateField = document.querySelector('[data-register-form] [name="state"]');
        const phone = document.querySelector('[data-register-form] [name="phone"]');
        phone.value = '11987654321';
        phone.dispatchEvent(new window.Event('input', { bubbles: true }));

        expect(stateField.tagName).toBe('SELECT');
        expect(stateField.options).toHaveLength(28);
        expect(phone.value).toBe('(11) 98765-4321');
        expect(document.querySelector('[data-register-form] [name="password"]').minLength).toBe(6);
        expect(document.querySelector('[data-register-form] [name="password_confirmation"]').minLength).toBe(6);
    });

    it('monta o payload com challenge, assinatura e perfil do papel', async () => {
        const { calls } = await openRegister({
            'POST /auth/wallet/challenge': { status: 201, body: { data: { id: 11, message: 'prove' } } },
            'POST /auth/register': { status: 201, body: { data: { id: 12 } } },
        });

        window.solana = {
            connect: vi.fn().mockResolvedValue({ publicKey: { toString: () => 'ATB7z1UtmGPGgMbvvcMfLuXdBvtsfbD1k77A4yHymvap' } }),
            signMessage: vi.fn().mockResolvedValue({ signature: new Uint8Array([9]) }),
        };
        await click('[data-connect-wallet]');
        document.querySelector('[data-auth-tab="register"]').click();
        await flush();

        const form = document.querySelector('[data-register-form]');
        form.querySelector('[name="name"]').value = 'Sítio Boa Colheita';
        form.querySelector('[name="email"]').value = 'produtor@foodrescue.test';
        form.querySelector('[name="password"]').value = 'uma-senha-bem-longa';
        form.querySelector('[name="password_confirmation"]').value = 'uma-senha-bem-longa';
        form.querySelector('[name="phone"]').value = '+55 11 99999-0000';
        form.querySelector('[name="document_number"]').value = '123456789';
        form.querySelector('[name="city"]').value = 'Mogi das Cruzes';
        form.querySelector('[name="state"]').value = 'SP';
        form.querySelector('[name="address_line"]').value = 'Zona Rural, km 12';
        await submit('[data-register-form]');

        const body = calls.find((call) => call.path === '/auth/register').body;
        expect(body.role).toBe('producer');
        expect(body.wallet_challenge_id).toBe(11);
        expect(body.wallet_signature).toBe('CQ==');
        expect(body.solana_wallet_address).toBe('ATB7z1UtmGPGgMbvvcMfLuXdBvtsfbD1k77A4yHymvap');
        expect(body.profile.country).toBe('Brasil');
        expect(body.profile.producer_type).toBe('individual');
        expect(body.profile.document_number).toBe('123456789');
        delete window.solana;
    });

    it('erro de validação do cadastro aparece no formulário', async () => {
        await openRegister({
            'POST /auth/wallet/challenge': { status: 201, body: { data: { id: 11, message: 'prove' } } },
            'POST /auth/register': { status: 422, body: { message: 'Dados inválidos.', errors: { email: ['O e-mail já está em uso.'] } } },
        });

        window.solana = {
            connect: vi.fn().mockResolvedValue({ publicKey: { toString: () => 'ATB7z1UtmGPGgMbvvcMfLuXdBvtsfbD1k77A4yHymvap' } }),
            signMessage: vi.fn().mockResolvedValue({ signature: new Uint8Array([9]) }),
        };
        await click('[data-connect-wallet]');
        document.querySelector('[data-auth-tab="register"]').click();
        await flush();
        await submit('[data-register-form]');

        expect(document.querySelector('[data-register-form] [data-form-message]').textContent)
            .toBe('O e-mail já está em uso.');
        delete window.solana;
    });
});
