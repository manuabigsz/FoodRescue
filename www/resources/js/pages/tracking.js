import { openAuthOrDashboard } from '../auth/modal.js';
import { api } from '../core/api.js';
import { config } from '../core/config.js';
import { esc, formatDateTime, money } from '../core/format.js';
import { nextActions, statusLabels, timelineSteps } from '../core/labels.js';
import { state } from '../core/state.js';
import { detailCell, setPage, solscanAddress } from '../core/ui.js';
import { bindTradeActions, renderTradeActions } from './tracking-actions.js';

export function tradeTitle(trade) {
    const product = trade.surplus_lot?.product?.name;
    if (!product) return (trade.is_donation ? 'Doação' : 'Compra') + ' #' + trade.id;

    return product + ' · ' + trade.surplus_lot.quantity + ' ' + trade.surplus_lot.unit;
}

export function tradeRoute(trade) {
    const shipping = trade.shipping_request;
    if (shipping) return shipping.origin.city + ' → ' + shipping.destination.city;
    const origin = trade.surplus_lot?.origin;

    return origin ? 'Origem: ' + origin.city + ', ' + origin.state : 'Logística ainda não definida';
}

export function selectedTradeId() {
    const query = location.hash.split('?')[1] || '';
    const id = Number(new URLSearchParams(query).get('id'));

    return Number.isInteger(id) && id > 0 ? id : state.selectedTradeId;
}

export async function renderTracking() {
    state.trackingPanel = null;
    setPage(
        '<div class="page"><div class="shell"><div class="page-head"><div><span class="eyebrow">RASTREABILIDADE</span><h1>Acompanhe cada etapa</h1><p>Pagamento, logística, entrega e comprovação com estados objetivos do início ao fim.</p></div></div>' +
        '<div data-tracking><div class="skeleton" style="min-height:220px"></div></div></div></div>',
        'Acompanhar entrega',
    );
    const target = document.querySelector('[data-tracking]');
    if (!target) return;
    if (!state.token) {
        target.innerHTML = '<div class="empty-state"><h2>Entre para acompanhar suas operações</h2><p>A lista vem da sua conta na API.</p><button class="button" type="button" data-open-auth>Entrar na conta</button></div>';
        target.querySelector('[data-open-auth]').addEventListener('click', function () { openAuthOrDashboard('login'); });

        return;
    }

    try {
        state.trades = await api('/trades', { query: { per_page: 20 } });
    } catch (error) {
        target.innerHTML = '<div class="empty-state"><h2>Não foi possível carregar as operações</h2><p>' + esc(error.message) + '</p></div>';

        return;
    }

    if (!state.trades.length) {
        target.innerHTML = '<div class="empty-state"><h2>Você ainda não tem operações</h2><p>Reserve um excedente ou aceite uma doação para acompanhar o fluxo aqui.</p><a class="button" href="#/catalogo">Ver excedentes →</a></div>';

        return;
    }

    const wanted = selectedTradeId();
    const trade = state.trades.find(function (item) { return item.id === wanted; }) || state.trades[0];
    state.selectedTradeId = trade.id;
    paintTracking(trade);
}

export function paintTracking(trade) {
    const target = document.querySelector('[data-tracking]');
    if (!target) return;
    const steps = timelineSteps.filter(function (step) { return !step.donationOnly || trade.is_donation; });
    const current = steps.findIndex(function (step) { return step.matches.includes(trade.status); });
    const closed = trade.status === 'cancelled' || trade.status === 'expired';
    const shipping = trade.shipping_request;
    const carrier = shipping?.selected_offer;
    const onChain = trade.blockchain?.trade_pda || config.programId;

    target.innerHTML =
        (state.trades.length > 1
            ? '<div class="trade-picker" role="tablist">' + state.trades.map(function (item) {
                return '<button type="button" role="tab" class="' + (item.id === trade.id ? 'active' : '') + '" aria-selected="' + (item.id === trade.id) + '" data-trade="' + item.id + '">' +
                    '<strong>#' + item.id + '</strong><span>' + esc(tradeTitle(item)) + '</span><small>' + esc(statusLabels[item.status] || item.status) + '</small></button>';
            }).join('') + '</div>'
            : '') +
        '<article class="timeline-card"><div class="trade-summary"><div><h2>Operação #' + trade.id + '</h2><p>' + (trade.is_donation ? 'Doação de excedente' : 'Compra de excedente') + ' · ' + esc(tradeRoute(trade)) + '</p></div><span class="status-pill">' + esc(statusLabels[trade.status] || trade.status) + '</span></div>' +
        (closed
            ? '<p class="demo-banner"><span>' + esc(nextActions[trade.status]) + (trade.cancellation_reason ? ' Motivo: ' + esc(trade.cancellation_reason) : '') + '</span></p>'
            : '<div class="timeline">' + steps.map(function (step, index) {
                return '<div class="timeline-step ' + (index < current ? 'done' : index === current ? 'active' : '') + '"><span>' + step.label + '</span></div>';
            }).join('') + '</div>') +
        '<div class="details-grid">' +
            detailCell('Total da operação', money(trade.buyer_total) + ' FRUSD') +
            detailCell('Produto', money(trade.product_amount) + ' FRUSD') +
            detailCell('Frete', Number(trade.shipping_amount) > 0 ? money(trade.shipping_amount) + ' FRUSD' : 'Sem frete contratado') +
            detailCell('Transportadora', carrierLabel(trade)) +
            detailCell('Previsão de entrega', formatDateTime(carrier?.estimated_delivery_at)) +
            detailCell('Prazo de pagamento', formatDateTime(trade.payment_expires_at)) +
        '</div>' +
        '<div class="panel-grid" style="margin-top:1rem"><div class="panel"><h3>Próxima ação</h3><p>' + esc(nextActions[trade.status] || 'Acompanhe a evolução dos estados.') + '</p></div>' +
        '<div class="panel"><h3>Registro on-chain</h3><p>' + (trade.blockchain?.trade_pda ? 'Conta desta operação no programa FoodRescue.' : 'A custódia ainda não foi criada; o link aponta para o programa.') + '</p>' +
        '<a class="button button-ghost button-small" href="' + solscanAddress(onChain) + '" target="_blank" rel="noopener">Ver no Solscan ↗</a></div></div>' +
        (trade.rescue_proof?.confirmed_at ? '<p class="footer-note">Proof of Rescue confirmado em ' + esc(formatDateTime(trade.rescue_proof.confirmed_at)) + '.</p>' : '') +
        renderTradeActions(trade) +
        '</article>';

    bindTradeActions(trade);
    target.querySelectorAll('[data-trade]').forEach(function (button) {
        button.addEventListener('click', function () {
            const selected = state.trades.find(function (item) { return item.id === Number(button.dataset.trade); });
            state.selectedTradeId = selected.id;
            state.trackingPanel = null;
            paintTracking(selected);
        });
    });
}

export function carrierLabel(trade) {
    const shipping = trade.shipping_request;
    if (!shipping) return 'Logística não definida';
    if (shipping.selected_offer?.carrier?.name) return shipping.selected_offer.carrier.name;
    if (shipping.status === 'buyer_managed') return 'Transporte pelo destinatário';
    if (shipping.status === 'quoting') return 'Aguardando cotações';

    return 'A definir';
}

export async function refreshTracking(tradeId) {
    state.trades = await api('/trades', { query: { per_page: 20 } });
    const trade = state.trades.find(function (item) { return item.id === tradeId; }) || state.trades[0];
    state.selectedTradeId = trade?.id || null;
    if (trade) paintTracking(trade);
}
