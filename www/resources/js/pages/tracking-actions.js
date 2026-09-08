import { api } from '../core/api.js';
import { esc, formatDateTime, money, short } from '../core/format.js';
import { currentRole } from '../core/session.js';
import { state } from '../core/state.js';
import { panelShell, toast } from '../core/ui.js';
import { refreshTracking, selectedTradeId } from './tracking.js';

export function isProducerOf(trade) {
    return state.user?.id === trade.producer_id;
}

export function isRecipientOf(trade) {
    return state.user?.id === trade.buyer_id;
}

export function selfManagedShipping(trade) {
    return trade.shipping_request?.status === 'buyer_managed';
}

/**
 * Ações disponíveis para o usuário no estado atual da operação. A regra de
 * autorização real está no backend; aqui só evitamos oferecer o que certamente
 * seria recusado.
 */

export function tradeActions(trade) {
    const actions = [];
    const recipient = isRecipientOf(trade);
    const producer = isProducerOf(trade);
    const carrier = currentRole() === 'carrier';

    if (trade.status === 'reserved' && recipient) {
        actions.push(['shipping', 'Solicitar cotação de frete', '']);
        actions.push(['self-shipping', 'Cuidar do transporte', 'button-ghost']);
    }
    if (trade.status === 'shipping_quotation' && recipient) {
        actions.push(['quotes', 'Ver cotações recebidas', '']);
    }
    if (['carrier_selected', 'buyer_managed', 'waiting_payment'].includes(trade.status) && recipient) {
        actions.push(['payment', 'Dados do pagamento', '']);
    }
    if (trade.status === 'funded' && producer) {
        actions.push(['ready', 'Liberar para coleta', '']);
    }
    if (trade.status === 'ready_for_pickup' && (carrier || (recipient && selfManagedShipping(trade)))) {
        actions.push(['pickup', 'Confirmar coleta', '']);
    }
    if (trade.status === 'in_transit' && recipient) {
        actions.push(['delivered', 'Confirmar entrega', '']);
    }
    if (['delivered', 'proof_pending', 'completed'].includes(trade.status) && (recipient || producer)) {
        actions.push(['rating', 'Avaliar contraparte', 'button-ghost']);
    }
    if (!['completed', 'cancelled', 'expired'].includes(trade.status) && (recipient || producer)) {
        actions.push(['cancel', 'Cancelar operação', 'button-ghost']);
    }

    return actions;
}

export function renderTradeActions(trade) {
    const actions = tradeActions(trade);
    if (!actions.length && !state.trackingPanel) return '';

    return '<div class="action-bar">' + actions.map(function (action) {
        return '<button class="button button-small ' + action[2] + '" type="button" data-action="' + action[0] + '">' + action[1] + '</button>';
    }).join('') + '</div><div data-action-panel></div>';
}

export function bindTradeActions(trade) {
    document.querySelectorAll('[data-action]').forEach(function (button) {
        button.addEventListener('click', function () { openActionPanel(trade, button.dataset.action); });
    });
    if (state.trackingPanel) openActionPanel(trade, state.trackingPanel, true);
}

export function openActionPanel(trade, action, keepOpen) {
    const target = document.querySelector('[data-action-panel]');
    if (!target) return;
    if (state.trackingPanel === action && !keepOpen) {
        state.trackingPanel = null;
        target.innerHTML = '';

        return;
    }
    state.trackingPanel = action;
    const panels = {
        shipping: shippingPanel,
        'self-shipping': selfShippingPanel,
        quotes: quotesPanel,
        payment: paymentPanel,
        ready: confirmationPanel('Liberar o lote para coleta', 'O produtor declara que a carga está pronta. O estado passa para “Pronto para coleta”.', '/delivery/ready-for-pickup'),
        pickup: confirmationPanel('Confirmar a coleta', 'Registra a retirada da carga e move a operação para “Em trânsito”.', '/delivery/pickup'),
        delivered: confirmationPanel('Confirmar a entrega', 'O destinatário declara que recebeu a carga. Em doações, o próximo passo é o Proof of Rescue.', '/delivery/delivered'),
        rating: ratingPanel,
        cancel: cancelPanel,
    };
    (panels[action] || function () {})(trade, target);
}

export function destinationFields() {
    return '<div class="field"><label>Endereço de destino</label><input name="destination_address" maxlength="255" required></div>' +
        '<div class="field-row"><div class="field"><label>Cidade</label><input name="destination_city" maxlength="120" required></div>' +
        '<div class="field"><label>Estado</label><input name="destination_state" maxlength="80" required></div></div>';
}

export async function submitPanel(target, request) {
    const message = target.querySelector('[data-panel-message]');
    const button = target.querySelector('[type="submit"], [data-panel-confirm]');
    if (message) message.textContent = '';
    if (button) button.disabled = true;
    try {
        await request();
        state.trackingPanel = null;
        await refreshTracking(state.selectedTradeId);
    } catch (error) {
        if (message) message.textContent = error.message;
        if (button) button.disabled = false;
    }
}

export function shippingPanel(trade, target) {
    target.innerHTML = panelShell(
        'Solicitar cotação de frete',
        '<p>As transportadoras verão esta rota e enviarão cotações. Você escolhe uma antes do pagamento.</p>' +
        '<form class="form-grid" data-panel-form>' + destinationFields() + '<button class="button button-small" type="submit">Abrir cotação</button></form>',
    );
    target.querySelector('[data-panel-form]').addEventListener('submit', function (event) {
        event.preventDefault();
        const fields = new FormData(event.currentTarget);
        submitPanel(target, function () {
            return api('/trades/' + trade.id + '/shipping', {
                method: 'POST',
                body: JSON.stringify({
                    destination_address: fields.get('destination_address'),
                    destination_city: fields.get('destination_city'),
                    destination_state: fields.get('destination_state'),
                    destination_country: 'BR',
                }),
            });
        });
    });
}

export function selfShippingPanel(trade, target) {
    const path = trade.is_donation ? '/ngo-managed' : '/buyer-managed';
    target.innerHTML = panelShell(
        'Cuidar do transporte',
        '<p>Você assume a retirada e o transporte da carga. Não haverá frete contratado nem cotação de transportadora.</p>' +
        '<form class="form-grid" data-panel-form>' + destinationFields() + '<button class="button button-small" type="submit">Assumir o transporte</button></form>',
    );
    target.querySelector('[data-panel-form]').addEventListener('submit', function (event) {
        event.preventDefault();
        const fields = new FormData(event.currentTarget);
        submitPanel(target, function () {
            return api('/trades/' + trade.id + path, {
                method: 'POST',
                body: JSON.stringify({
                    destination_address: fields.get('destination_address'),
                    destination_city: fields.get('destination_city'),
                    destination_state: fields.get('destination_state'),
                    destination_country: 'BR',
                }),
            });
        });
    });
}

export async function quotesPanel(trade, target) {
    target.innerHTML = panelShell('Cotações recebidas', '<div class="skeleton" style="min-height:80px"></div>');
    let offers;
    try {
        offers = await api('/trades/' + trade.id + '/shipping-offers');
    } catch (error) {
        target.querySelector('[data-panel-message]').textContent = error.message;

        return;
    }

    const pending = offers.filter(function (offer) { return offer.status === 'pending'; });
    target.innerHTML = panelShell(
        'Cotações recebidas',
        pending.length
            ? '<div class="quote-list">' + pending.map(function (offer) {
                return '<div class="quote-row"><div><strong>' + esc(offer.carrier?.name || 'Transportadora') + '</strong>' +
                    '<small>Coleta ' + esc(formatDateTime(offer.pickup_at)) + ' · entrega ' + esc(formatDateTime(offer.estimated_delivery_at)) + '</small></div>' +
                    '<strong>' + money(offer.amount) + ' FRUSD</strong>' +
                    '<button class="button button-small" type="button" data-select-offer="' + offer.id + '">Selecionar</button></div>';
            }).join('') + '</div>'
            : '<p>Nenhuma cotação ainda. As transportadoras têm até o fim do prazo de cotação para responder.</p>',
    );

    target.querySelectorAll('[data-select-offer]').forEach(function (button) {
        button.addEventListener('click', function () {
            submitPanel(target, function () {
                return api('/trades/' + trade.id + '/shipping-offers/' + button.dataset.selectOffer + '/select', { method: 'POST', body: '{}' });
            });
        });
    });
}

export async function paymentPanel(trade, target) {
    target.innerHTML = panelShell('Pagamento em custódia', '<div class="skeleton" style="min-height:80px"></div>');
    let preparation;
    try {
        preparation = await api('/trades/' + trade.id + '/blockchain/prepare', { method: 'POST', body: '{}' });
    } catch (error) {
        target.innerHTML = panelShell('Pagamento em custódia', '<p>Não foi possível preparar a instrução: ' + esc(error.message) + '</p>');

        return;
    }

    const rows = [
        ['Programa', preparation.program_id],
        ['Mint FRUSD', preparation.mint],
        ['Trade PDA', preparation.accounts_seeds?.trade || preparation.trade_pda],
        ['Vault', preparation.accounts_seeds?.vault || preparation.vault_token_account],
        ['Carteira pagadora', preparation.wallets?.buyer],
    ].filter(function (row) { return Boolean(row[1]); });

    target.innerHTML = panelShell(
        'Pagamento em custódia',
        '<p>O backend preparou a instrução, mas <strong>não assina a transação</strong>. A assinatura é feita pela carteira, com os scripts em <code>solana/</code> deste repositório.</p>' +
        '<div class="details-grid">' + rows.map(function (row) {
            return '<div class="detail"><small>' + esc(row[0]) + '</small><strong title="' + esc(row[1]) + '">' + esc(short(String(row[1]), 8, 6)) + '</strong></div>';
        }).join('') + '</div>' +
        '<p class="footer-note">Depois de enviar a transação, confirme com <code>POST /trades/' + trade.id + '/blockchain/funding/confirm</code> passando a assinatura. Só então o estado passa para “Pagamento em custódia”.</p>' +
        '<button class="button button-ghost button-small" type="button" data-copy-preparation>Copiar dados da instrução</button>',
    );

    target.querySelector('[data-copy-preparation]').addEventListener('click', function () {
        navigator.clipboard?.writeText(JSON.stringify(preparation, null, 2))
            .then(function () { toast('Dados da instrução copiados.'); })
            .catch(function () { toast('Não foi possível copiar.', 'error'); });
    });
}

export function confirmationPanel(title, description, path) {
    return function (trade, target) {
        target.innerHTML = panelShell(title, '<p>' + description + '</p><button class="button button-small" type="button" data-panel-confirm>Confirmar</button>');
        target.querySelector('[data-panel-confirm]').addEventListener('click', function () {
            submitPanel(target, function () {
                return api('/trades/' + trade.id + path, { method: 'POST', body: '{}' });
            });
        });
    };
}

export function ratingPanel(trade, target) {
    const counterpartId = isProducerOf(trade) ? trade.buyer_id : trade.producer_id;
    const counterpartLabel = isProducerOf(trade) ? (trade.is_donation ? 'a organização social' : 'o comprador') : 'o produtor';
    target.innerHTML = panelShell(
        'Avaliar ' + counterpartLabel,
        '<form class="form-grid" data-panel-form><div class="field"><label for="rating-value">Nota</label>' +
        '<select id="rating-value" name="rating">' + [5, 4, 3, 2, 1].map(function (value) {
            return '<option value="' + value + '">' + value + ' — ' + ['Péssimo', 'Ruim', 'Regular', 'Bom', 'Excelente'][value - 1] + '</option>';
        }).join('') + '</select></div>' +
        '<div class="field"><label for="rating-comment">Comentário (opcional)</label><textarea id="rating-comment" name="comment" maxlength="1000"></textarea></div>' +
        '<button class="button button-small" type="submit">Enviar avaliação</button></form>',
    );
    target.querySelector('[data-panel-form]').addEventListener('submit', function (event) {
        event.preventDefault();
        const fields = new FormData(event.currentTarget);
        submitPanel(target, function () {
            const payload = { target_user_id: counterpartId, rating: Number(fields.get('rating')) };
            if (fields.get('comment')) payload.comment = fields.get('comment');

            return api('/trades/' + trade.id + '/ratings', { method: 'POST', body: JSON.stringify(payload) });
        }).then(function () { toast('Avaliação registrada.'); });
    });
}

export function cancelPanel(trade, target) {
    target.innerHTML = panelShell(
        'Cancelar operação',
        '<p>O lote volta ao catálogo quando não houver pagamento em custódia. Operações já financiadas exigem o cancelamento on-chain, com estorno.</p>' +
        '<form class="form-grid" data-panel-form><div class="field"><label for="cancel-reason">Motivo (opcional)</label><input id="cancel-reason" name="reason" maxlength="500"></div>' +
        '<button class="button button-small button-ghost" type="submit">Confirmar cancelamento</button></form>',
    );
    target.querySelector('[data-panel-form]').addEventListener('submit', function (event) {
        event.preventDefault();
        const reason = new FormData(event.currentTarget).get('reason');
        submitPanel(target, function () {
            return api('/trades/' + trade.id + '/cancel', { method: 'POST', body: JSON.stringify(reason ? { reason: reason } : {}) });
        });
    });
}
