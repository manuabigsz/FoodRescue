import { describe, expect, it } from 'vitest';
import { bootApp, click, sampleUser, trade } from './helpers/boot.js';

const producer = { ...sampleUser, id: 7, roles: ['producer'] };
const buyer = { ...sampleUser, id: 8, roles: ['buyer'] };
const escrow = { trade_pda: '7BrDDUdFDsnEHhHcDd1nf8GghVBYCGbzK8znoKCjykHk', vault_token_account: 'CD2JoQYt8QK96ZASMf4YedNFzhNNqen7d3RtNRovefcU' };

function boot(user, overrides) {
    return bootApp({
        hash: '#/acompanhamento',
        session: { user },
        routes: { 'GET /auth/me': { data: user }, 'GET /trades': { data: [trade(overrides)] } },
    });
}

describe('operação com custódia on-chain', () => {
    it('sem escrow o produtor confirma a coleta pela própria tela', async () => {
        await boot(producer, { status: 'funded', blockchain: null });

        const actions = [...document.querySelectorAll('[data-action]')].map((b) => b.dataset.action);
        expect(actions).toContain('ready');
        expect(actions).not.toContain('onchain-ready');
    });

    it('com escrow o botão vira orientação, porque o backend exigiria assinatura', async () => {
        await boot(producer, { status: 'funded', blockchain: escrow });

        const actions = [...document.querySelectorAll('[data-action]')].map((b) => b.dataset.action);
        expect(actions).toContain('onchain-ready');
        expect(actions).not.toContain('ready');
    });

    it('a orientação diz quem assina e qual comando rodar', async () => {
        await boot(producer, { id: 10, status: 'funded', blockchain: escrow });

        await click('[data-action="onchain-ready"]');
        const painel = document.querySelector('[data-action-panel]').textContent;

        expect(painel).toContain('exige assinatura');
        expect(painel).toContain('produtor');
        expect(painel).toContain('TRADE_ID=10');
        expect(painel).toContain('npm run e2e:delivery');
    });

    it('entregue com escrow oferece a liquidação ao destinatário', async () => {
        await boot(buyer, { status: 'delivered', blockchain: escrow });

        const actions = [...document.querySelectorAll('[data-action]')].map((b) => b.dataset.action);
        expect(actions).toContain('onchain-settlement');

        await click('[data-action="onchain-settlement"]');
        expect(document.querySelector('[data-action-panel]').textContent).toContain('npm run e2e:settlement');
    });

    it('as ações que não dependem da cadeia continuam disponíveis', async () => {
        await boot(buyer, { status: 'reserved', blockchain: null });

        const actions = [...document.querySelectorAll('[data-action]')].map((b) => b.dataset.action);
        expect(actions).toEqual(expect.arrayContaining(['shipping', 'self-shipping', 'cancel']));
    });

    it('operação concluída mostra o PDA real no link do explorer', async () => {
        await boot(buyer, { status: 'completed', blockchain: escrow });

        const link = [...document.querySelectorAll('a')].find((a) => a.href.includes('solscan'));
        expect(link.href).toContain(escrow.trade_pda);
    });
});
