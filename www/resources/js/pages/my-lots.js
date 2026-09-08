import { api } from '../core/api.js';
import { esc, formatDateTime, localDateTimeValue, money, numberChanged, quantityValue } from '../core/format.js';
import { bindUsdMasks, formatUsd, normalizeUsd } from '../core/form-fields.js';
import { offerStatusLabels, surplusStatusLabels } from '../core/labels.js';
import { currentRole } from '../core/session.js';
import { state } from '../core/state.js';
import { panelShell, setPage, toast } from '../core/ui.js';

export async function renderMyLots() {
    setPage(
        '<div class="page"><div class="shell"><div class="page-head"><div><span class="eyebrow">PRODUTOR</span><h1>Meus lotes</h1><p>Histórico completo dos seus excedentes, com as propostas recebidas em cada um.</p></div><a class="button" href="#/publicar">+ Publicar excedente</a></div>' +
        '<div data-my-lots><div class="skeleton" style="min-height:200px"></div></div></div></div>',
        'Meus lotes',
    );
    const target = document.querySelector('[data-my-lots]');
    if (!target) return;

    if (currentRole() !== 'producer') {
        target.innerHTML = '<div class="empty-state"><h2>Área do produtor</h2><p>' + (state.token ? 'Esta lista é exclusiva de contas com o papel Produtor.' : 'Entre com uma conta de produtor para ver seus lotes.') + '</p></div>';

        return;
    }

    let lots;
    try {
        lots = await api('/surplus', { query: { mine: 1, per_page: 50 } });
    } catch (error) {
        target.innerHTML = '<div class="empty-state"><h2>Não foi possível carregar seus lotes</h2><p>' + esc(error.message) + '</p></div>';

        return;
    }

    if (!lots.length) {
        target.innerHTML = '<div class="empty-state"><h2>Você ainda não publicou excedentes</h2><p>Cadastre um lote para começar a receber propostas.</p><a class="button" href="#/publicar">Publicar excedente →</a></div>';

        return;
    }

    target.innerHTML = lots.map(function (lot) {
        return '<article class="panel lot-row" data-lot-row="' + lot.id + '">' +
            '<div class="trade-summary"><div><h2>' + esc(lot.product?.name || 'Produto') + '</h2>' +
            '<p>' + esc(quantityValue(lot.quantity)) + ' ' + esc(lot.unit) + ' · ' + esc(lot.origin.city) + ', ' + esc(lot.origin.state) + ' · ' + money(lot.asking_price) + ' FRUSD' +
            (lot.minimum_price ? ' (mínimo ' + money(lot.minimum_price) + ')' : '') + '</p></div>' +
            '<span class="status-pill">' + esc(surplusStatusLabels[lot.status] || lot.status) + '</span></div>' +
            '<div class="action-bar"><button class="button button-small button-ghost" type="button" data-offers="' + lot.id + '">Ver propostas</button>' +
            (lot.status === 'open' ? '<button class="button button-small button-ghost" type="button" data-edit-lot="' + lot.id + '">Editar</button>' : '') +
            (lot.status === 'open' ? '<button class="button button-small button-ghost" type="button" data-cancel-lot="' + lot.id + '">Cancelar lote</button>' : '') +
            '<small class="footer-note">Disponível até ' + esc(formatDateTime(lot.available_until)) + '</small></div>' +
            '<div data-offers-panel="' + lot.id + '"></div></article>';
    }).join('');

    target.querySelectorAll('[data-offers]').forEach(function (button) {
        button.addEventListener('click', function () { paintLotOffers(Number(button.dataset.offers)); });
    });
    target.querySelectorAll('[data-edit-lot]').forEach(function (button) {
        button.addEventListener('click', function () { paintLotEditor(lots.find(function (lot) { return lot.id === Number(button.dataset.editLot); })); });
    });
    target.querySelectorAll('[data-cancel-lot]').forEach(function (button) {
        button.addEventListener('click', async function () {
            button.disabled = true;
            try {
                await api('/surplus/' + button.dataset.cancelLot + '/cancel', { method: 'POST', body: '{}' });
                toast('Lote cancelado.');
                renderMyLots();
            } catch (error) {
                toast(error.message, 'error');
                button.disabled = false;
            }
        });
    });
}

export async function paintLotOffers(lotId) {
    const panel = document.querySelector('[data-offers-panel="' + lotId + '"]');
    if (!panel) return;
    if (panel.innerHTML) {
        panel.innerHTML = '';

        return;
    }
    panel.innerHTML = '<div class="skeleton" style="min-height:70px"></div>';

    let offers;
    try {
        offers = await api('/surplus/' + lotId + '/offers');
    } catch (error) {
        panel.innerHTML = '<p class="form-message">' + esc(error.message) + '</p>';

        return;
    }

    if (!offers.length) {
        panel.innerHTML = '<p class="footer-note">Nenhuma proposta recebida neste lote.</p>';

        return;
    }

    panel.innerHTML = '<div class="quote-list">' + offers.map(function (offer) {
        const pending = offer.status === 'pending';

        return '<div class="quote-row"><div><strong>' + esc(offer.buyer?.name || 'Comprador') + '</strong>' +
            '<small>' + esc(offerStatusLabels[offer.status] || offer.status) + (offer.expires_at ? ' · expira ' + esc(formatDateTime(offer.expires_at)) : '') + '</small></div>' +
            '<strong>' + money(offer.amount) + ' FRUSD</strong>' +
            (pending
                ? '<span class="action-bar"><button class="button button-small" type="button" data-accept-offer="' + offer.id + '">Aceitar</button>' +
                  '<button class="button button-small button-ghost" type="button" data-reject-offer="' + offer.id + '">Recusar</button></span>'
                : '<span></span>') +
            '</div>';
    }).join('') + '</div>';

    const respond = function (attribute, path, done) {
        panel.querySelectorAll('[' + attribute + ']').forEach(function (button) {
            button.addEventListener('click', async function () {
                button.disabled = true;
                try {
                    await api('/offers/' + button.getAttribute(attribute) + path, { method: 'POST', body: '{}' });
                    toast(done);
                    renderMyLots();
                } catch (error) {
                    toast(error.message, 'error');
                    button.disabled = false;
                }
            });
        });
    };
    respond('data-accept-offer', '/accept', 'Proposta aceita. A operação já está no acompanhamento.');
    respond('data-reject-offer', '/reject', 'Proposta recusada.');
}

export function paintLotEditor(lot) {
    const panel = document.querySelector('[data-offers-panel="' + lot.id + '"]');
    if (!panel) return;
    if (panel.dataset.mode === 'editor') {
        panel.innerHTML = '';
        delete panel.dataset.mode;

        return;
    }
    panel.dataset.mode = 'editor';
    panel.innerHTML = panelShell(
        'Editar lote',
        '<p>Só lotes abertos podem ser alterados. Deixe um campo em branco para mantê-lo como está.</p>' +
        '<form class="form-grid" data-edit-form>' +
        '<div class="field-row"><div class="field"><label>Quantidade (' + esc(lot.unit) + ')</label><input name="quantity" type="number" step="0.001" min="0.001" value="' + esc(lot.quantity) + '"></div>' +
        '<div class="field"><label>Disponível até</label><input name="available_until" type="datetime-local" value="' + esc(localDateTimeValue(new Date(lot.available_until))) + '"></div></div>' +
            '<div class="field-row"><div class="field"><label>Preço total do lote (FRUSD)</label><input name="asking_price" data-usd-mask value="' + esc(formatUsd(lot.asking_price)) + '"></div>' +
            '<div class="field"><label>Preço mínimo total (privado)</label><input name="minimum_price" data-usd-mask value="' + esc(lot.minimum_price ? formatUsd(lot.minimum_price) : '') + '"></div></div>' +
        '<label class="check-line"><input type="checkbox" name="donation_eligible"' + (lot.donation_eligible ? ' checked' : '') + '> Aceita doação</label>' +
        '<button class="button button-small" type="submit">Salvar alterações</button></form>',
    );

    bindUsdMasks(panel);
    panel.querySelector('[data-edit-form]').addEventListener('submit', async function (event) {
        event.preventDefault();
        const form = event.currentTarget;
        const message = panel.querySelector('[data-panel-message]');
        const button = form.querySelector('[type="submit"]');
        const fields = new FormData(form);
        const payload = {};
        message.textContent = '';

        /**
         * Um input numérico devolve "680", não "680.000", e o datetime-local não
         * tem segundos. Comparar como texto marcaria todo campo como alterado, o
         * que reenviaria o lote inteiro e ainda truncaria o prazo a cada edição.
         */
        if (numberChanged(fields.get('quantity'), lot.quantity)) payload.quantity = fields.get('quantity');
        const askingPrice = normalizeUsd(fields.get('asking_price'));
        if (numberChanged(askingPrice, lot.asking_price)) payload.asking_price = askingPrice;

        const minimum = normalizeUsd(fields.get('minimum_price'));
        if (minimum === '' && lot.minimum_price !== null) {
            payload.minimum_price = null;
        } else if (minimum !== '' && numberChanged(minimum, lot.minimum_price)) {
            payload.minimum_price = minimum;
        }

        const until = fields.get('available_until');
        if (until && until !== localDateTimeValue(new Date(lot.available_until))) {
            payload.available_until = new Date(until).toISOString();
        }
        if (form.querySelector('[name="donation_eligible"]').checked !== Boolean(lot.donation_eligible)) {
            payload.donation_eligible = form.querySelector('[name="donation_eligible"]').checked;
        }

        if (!Object.keys(payload).length) {
            message.textContent = 'Nada foi alterado.';

            return;
        }

        button.disabled = true;
        try {
            await api('/surplus/' + lot.id, { method: 'PATCH', body: JSON.stringify(payload) });
            toast('Lote atualizado.');
            renderMyLots();
        } catch (error) {
            message.textContent = error.message;
            button.disabled = false;
        }
    });
}
