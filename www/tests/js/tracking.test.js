import { describe, expect, it } from 'vitest';
import { bootApp, click, flush, sampleUser, trade } from './helpers/boot.js';

const buyer = { ...sampleUser, id: 8, name: 'Mercado Central', roles: ['buyer'] };
const producer = { ...sampleUser, id: 7, roles: ['producer'] };
const carrier = { ...sampleUser, id: 9, name: 'Rota Verde', roles: ['carrier'] };

function boot(user, trades, extraRoutes = {}) {
    return bootApp({
        hash: '#/acompanhamento',
        session: { user },
        routes: {
            'GET /auth/me': { data: user },
            'GET /trades': { data: trades },
            ...extraRoutes,
        },
    });
}

describe('acompanhamento de operações', () => {
    it('sem sessão pede login em vez de mostrar operação fictícia', async () => {
        const { calls } = await bootApp({ hash: '#/acompanhamento' });

        expect(calls.filter((call) => call.path === '/trades')).toHaveLength(0);
        expect(document.body.textContent).toContain('Entre para acompanhar');
        expect(document.body.textContent).not.toContain('Rota Verde Logística');
    });

    it('lista vazia convida ao catálogo', async () => {
        await boot(buyer, []);

        expect(document.body.textContent).toContain('ainda não tem operações');
    });

    it('monta a timeline a partir do status real e destaca a etapa atual', async () => {
        await boot(buyer, [trade({ status: 'in_transit' })]);

        const steps = [...document.querySelectorAll('.timeline-step')].map((step) => step.textContent.trim());
        expect(steps).toEqual(['Reservado', 'Transporte definido', 'Aguardando pagamento', 'Pagamento em custódia', 'Pronto para coleta', 'Em trânsito', 'Entregue', 'Concluído']);
        expect(document.querySelector('.timeline-step.active').textContent.trim()).toBe('Em trânsito');
        expect(document.querySelectorAll('.timeline-step.done')).toHaveLength(5);
    });

    it('acrescenta a etapa de comprovação só em doações', async () => {
        await boot(buyer, [trade({ is_donation: true, status: 'delivered' })]);

        expect(document.body.textContent).toContain('Comprovação pendente');
    });

    it('operação cancelada troca a timeline pelo motivo', async () => {
        await boot(buyer, [trade({ status: 'cancelled', cancellation_reason: 'Carga avariada' })]);

        expect(document.querySelector('.timeline')).toBeNull();
        expect(document.querySelector('.demo-banner').textContent).toContain('Carga avariada');
    });

    it('destinatário em reserved pode definir logística e cancelar, mas não liberar coleta', async () => {
        await boot(buyer, [trade({ status: 'reserved' })]);

        const actions = [...document.querySelectorAll('[data-action]')].map((button) => button.dataset.action);
        expect(actions).toContain('shipping');
        expect(actions).toContain('self-shipping');
        expect(actions).toContain('cancel');
        expect(actions).not.toContain('ready');
    });

    it('produtor em funded vê liberar para coleta e o comprador não', async () => {
        await boot(producer, [trade({ status: 'funded' })]);
        expect([...document.querySelectorAll('[data-action]')].map((b) => b.dataset.action)).toContain('ready');

        await boot(buyer, [trade({ status: 'funded' })]);
        expect([...document.querySelectorAll('[data-action]')].map((b) => b.dataset.action)).not.toContain('ready');
    });

    it('transportadora confirma coleta em ready_for_pickup', async () => {
        await boot(carrier, [trade({ status: 'ready_for_pickup' })]);

        expect([...document.querySelectorAll('[data-action]')].map((b) => b.dataset.action)).toContain('pickup');
    });

    it('operação concluída não oferece cancelamento e oferece avaliação', async () => {
        await boot(buyer, [trade({ status: 'completed' })]);

        const actions = [...document.querySelectorAll('[data-action]')].map((b) => b.dataset.action);
        expect(actions).toContain('rating');
        expect(actions).not.toContain('cancel');
    });

    it('solicitar cotação envia o destino e recarrega a operação', async () => {
        const { calls } = await boot(buyer, [trade({ status: 'reserved' })], {
            'POST /trades/10/shipping': { status: 201, body: { data: { id: 5, status: 'quoting' } } },
        });

        await click('[data-action="shipping"]');
        const form = document.querySelector('[data-panel-form]');
        form.querySelector('[name="destination_address"]').value = 'Av. Central, 100';
        form.querySelector('[name="destination_city"]').value = 'São Paulo';
        form.querySelector('[name="destination_state"]').value = 'SP';
        form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
        await flush();

        const request = calls.find((call) => call.path === '/trades/10/shipping');
        expect(request.body).toEqual({
            destination_address: 'Av. Central, 100',
            destination_city: 'São Paulo',
            destination_state: 'SP',
            destination_country: 'BR',
        });
        expect(calls.filter((call) => call.path === '/trades').length).toBeGreaterThan(1);
    });

    it('doação usa ngo-managed em vez de buyer-managed', async () => {
        const ngo = { ...sampleUser, id: 8, roles: ['ngo'] };
        const { calls } = await boot(ngo, [trade({ is_donation: true, status: 'reserved' })], {
            'POST /trades/10/ngo-managed': { data: { id: 10, status: 'waiting_payment' } },
        });

        await click('[data-action="self-shipping"]');
        const form = document.querySelector('[data-panel-form]');
        form.querySelector('[name="destination_address"]').value = 'Rua A';
        form.querySelector('[name="destination_city"]').value = 'Campinas';
        form.querySelector('[name="destination_state"]').value = 'SP';
        form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
        await flush();

        expect(calls.some((call) => call.path === '/trades/10/ngo-managed')).toBe(true);
        expect(calls.some((call) => call.path === '/trades/10/buyer-managed')).toBe(false);
    });

    it('confirmar entrega chama o endpoint de delivery', async () => {
        const { calls } = await boot(buyer, [trade({ status: 'in_transit' })], {
            'POST /trades/10/delivery/delivered': { data: { id: 10, status: 'delivered' } },
        });

        await click('[data-action="delivered"]');
        await click('[data-panel-confirm]');

        expect(calls.some((call) => call.path === '/trades/10/delivery/delivered')).toBe(true);
    });

    it('erro da API aparece no painel sem quebrar a tela', async () => {
        await boot(buyer, [trade({ status: 'in_transit' })], {
            'POST /trades/10/delivery/delivered': { status: 422, body: { message: 'Transição não permitida.' } },
        });

        await click('[data-action="delivered"]');
        await click('[data-panel-confirm]');

        expect(document.querySelector('[data-panel-message]').textContent).toBe('Transição não permitida.');
        expect(document.querySelector('.timeline-card')).not.toBeNull();
    });

    it('o painel de pagamento mostra a mensagem do backend quando o protocolo não está pronto', async () => {
        await boot(buyer, [trade({ status: 'waiting_payment' })], {
            'POST /trades/10/blockchain/prepare': { status: 503, body: { message: 'ProtocolConfig ainda não foi inicializado.' } },
        });

        await click('[data-action="payment"]');

        expect(document.querySelector('[data-action-panel]').textContent).toContain('ProtocolConfig ainda não foi inicializado.');
    });

    it('seleciona a operação pedida na URL', async () => {
        await bootApp({
            hash: '#/acompanhamento?id=11',
            session: { user: buyer },
            routes: {
                'GET /auth/me': { data: buyer },
                'GET /trades': { data: [trade({ id: 10 }), trade({ id: 11, status: 'funded' })] },
            },
        });

        expect(document.querySelector('.trade-summary h2').textContent).toContain('#11');
        expect(document.querySelector('.trade-picker .active').textContent).toContain('#11');
    });

    it('traduz o limite de requisições em vez de mostrar erro genérico', async () => {
        await boot(buyer, [trade({ status: 'in_transit' })], {
            'POST /trades/10/delivery/delivered': { status: 429, body: { message: 'Too Many Attempts.' } },
        });

        await click('[data-action="delivered"]');
        await click('[data-panel-confirm]');

        expect(document.querySelector('[data-panel-message]').textContent).toContain('Muitas ações em sequência');
    });
});
