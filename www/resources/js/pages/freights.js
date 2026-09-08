import { api } from '../core/api.js';
import { esc, formatDateTime } from '../core/format.js';
import { currentRole } from '../core/session.js';
import { state } from '../core/state.js';
import { setPage, toast } from '../core/ui.js';

export async function renderFreights() {
    setPage(
        '<div class="page"><div class="shell"><div class="page-head"><div><span class="eyebrow">LOGÍSTICA</span><h1>Rotas abertas para cotação</h1><p>Solicitações de frete aguardando proposta. Envie sua cotação com valor, coleta e previsão de entrega.</p></div></div>' +
        '<div data-freights><div class="skeleton" style="min-height:200px"></div></div></div></div>',
        'Rotas abertas',
    );
    const target = document.querySelector('[data-freights]');
    if (!target) return;

    if (currentRole() !== 'carrier') {
        target.innerHTML = '<div class="empty-state"><h2>Área da transportadora</h2><p>' + (state.token ? 'Esta lista é exclusiva de contas com o papel Transportadora.' : 'Entre com uma conta de transportadora para ver as rotas.') + '</p></div>';

        return;
    }

    let requests;
    try {
        requests = await api('/shipping-requests');
    } catch (error) {
        target.innerHTML = '<div class="empty-state"><h2>Não foi possível carregar as rotas</h2><p>' + esc(error.message) + '</p></div>';

        return;
    }

    if (!requests.length) {
        target.innerHTML = '<div class="empty-state"><h2>Nenhuma rota aberta</h2><p>Quando um destinatário solicitar cotação, a rota aparece aqui.</p></div>';

        return;
    }

    target.innerHTML = requests.map(function (request) {
        return '<article class="panel freight-card"><div class="trade-summary"><div><h2>' + esc(request.origin.city) + ' → ' + esc(request.destination.city) + '</h2>' +
            '<p>' + esc(request.quantity) + ' ' + esc(request.unit) + ' · operação #' + request.trade_id + ' · cotações até ' + esc(formatDateTime(request.quotation_expires_at)) + '</p></div></div>' +
            '<form class="form-grid" data-freight-form="' + request.id + '">' +
            '<div class="field-row"><div class="field"><label>Valor do frete (FRUSD)</label><input name="amount" type="number" step="0.000001" min="0.000001" required></div>' +
            '<div class="field"><label>Coleta em</label><input name="pickup_at" type="datetime-local" required></div></div>' +
            '<div class="field"><label>Previsão de entrega</label><input name="estimated_delivery_at" type="datetime-local" required></div>' +
            '<button class="button button-small" type="submit">Enviar cotação</button><p class="form-message" data-form-message></p></form></article>';
    }).join('');

    target.querySelectorAll('[data-freight-form]').forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            const message = form.querySelector('[data-form-message]');
            const button = form.querySelector('[type="submit"]');
            const fields = new FormData(form);
            message.textContent = '';
            button.disabled = true;
            try {
                await api('/shipping-requests/' + form.dataset.freightForm + '/offers', {
                    method: 'POST',
                    body: JSON.stringify({
                        amount: fields.get('amount'),
                        pickup_at: new Date(fields.get('pickup_at')).toISOString(),
                        estimated_delivery_at: new Date(fields.get('estimated_delivery_at')).toISOString(),
                    }),
                });
                toast('Cotação enviada.');
                renderFreights();
            } catch (error) {
                message.textContent = error.message;
                button.disabled = false;
            }
        });
    });
}
