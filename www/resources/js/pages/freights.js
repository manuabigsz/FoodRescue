import { api } from '../core/api.js';
import { esc, formatDateTime, localDateTimeValue, money, quantityValue } from '../core/format.js';
import { bindUsdMasks, formatUsd, normalizeUsd } from '../core/form-fields.js';
import { currentRole } from '../core/session.js';
import { state } from '../core/state.js';
import { setPage, toast } from '../core/ui.js';

/** Coleta na próxima manhã útil e entrega oito horas depois: o caso comum já preenchido. */
export function quoteDefaults(now = new Date()) {
    const pickup = new Date(now);
    pickup.setDate(pickup.getDate() + 1);
    pickup.setHours(8, 0, 0, 0);

    const delivery = new Date(pickup.getTime() + 8 * 3600000);

    return { pickup: localDateTimeValue(pickup), delivery: localDateTimeValue(delivery) };
}

/** Duração do trânsito em texto curto, ou o motivo de o par de datas ser inválido. */
export function transitLabel(pickupValue, deliveryValue) {
    if (!pickupValue || !deliveryValue) return { text: 'Informe coleta e entrega', invalid: true };

    const pickup = new Date(pickupValue);
    const delivery = new Date(deliveryValue);
    if (Number.isNaN(pickup.getTime()) || Number.isNaN(delivery.getTime())) return { text: 'Data inválida', invalid: true };
    if (pickup.getTime() <= Date.now()) return { text: 'A coleta precisa ser no futuro', invalid: true };
    if (delivery <= pickup) return { text: 'A entrega precisa ser depois da coleta', invalid: true };

    const minutos = Math.round((delivery - pickup) / 60000);
    const dias = Math.floor(minutos / 1440);
    const horas = Math.floor((minutos % 1440) / 60);
    const restoMinutos = minutos % 60;
    const partes = [];
    if (dias) partes.push(dias + (dias === 1 ? ' dia' : ' dias'));
    if (horas) partes.push(horas + 'h');
    if (!dias && restoMinutos) partes.push(restoMinutos + 'min');

    return { text: partes.join(' e ') + ' de trânsito', invalid: false };
}

/** A cotação pendente da própria transportadora, se já houver uma. */
export function minhaCotacao(request) {
    return (request.offers || []).find(function (offer) { return offer.status === 'pending'; }) || null;
}

function quoteFields(request) {
    const enviada = minhaCotacao(request);
    const padrao = enviada
        ? { pickup: localDateTimeValue(new Date(enviada.pickup_at)), delivery: localDateTimeValue(new Date(enviada.estimated_delivery_at)) }
        : quoteDefaults();
    const agora = localDateTimeValue(new Date());

    return '<div class="field-row"><div class="field"><label for="amount-' + request.id + '">Valor do frete (FRUSD)</label>' +
        '<input id="amount-' + request.id + '" name="amount" data-usd-mask placeholder="US$ 0.00" value="' + (enviada ? esc(formatUsd(enviada.amount)) : '') + '" required></div>' +
        '<div class="field"><label for="pickup-' + request.id + '">Coleta em</label>' +
        '<input id="pickup-' + request.id + '" name="pickup_at" type="datetime-local" value="' + padrao.pickup + '" min="' + agora + '" required></div></div>' +
        '<div class="field-row"><div class="field"><label for="delivery-' + request.id + '">Previsão de entrega</label>' +
        '<input id="delivery-' + request.id + '" name="estimated_delivery_at" type="datetime-local" value="' + padrao.delivery + '" min="' + padrao.pickup + '" required></div>' +
        '<div class="field"><span class="field-hint-label">Tempo de trânsito</span><output class="transit-hint" data-transit>' + esc(transitLabel(padrao.pickup, padrao.delivery).text) + '</output></div></div>';
}

/**
 * Mantém o par de datas coerente enquanto a transportadora digita: a entrega
 * nunca fica antes da coleta e o tempo de trânsito aparece na hora, em vez de
 * só descobrir o erro depois de enviar.
 */
function bindQuoteDates(form) {
    const pickup = form.querySelector('[name="pickup_at"]');
    const delivery = form.querySelector('[name="estimated_delivery_at"]');
    const hint = form.querySelector('[data-transit]');

    const refresh = function () {
        const resultado = transitLabel(pickup.value, delivery.value);
        hint.textContent = resultado.text;
        hint.classList.toggle('invalid', resultado.invalid);
    };

    pickup.addEventListener('change', function () {
        if (!pickup.value) return;
        const anterior = delivery.value ? new Date(delivery.value) - new Date(delivery.min || pickup.value) : 0;
        delivery.min = pickup.value;
        if (!delivery.value || new Date(delivery.value) <= new Date(pickup.value)) {
            const duracao = anterior > 0 ? anterior : 8 * 3600000;
            delivery.value = localDateTimeValue(new Date(new Date(pickup.value).getTime() + duracao));
        }
        refresh();
    });
    delivery.addEventListener('change', refresh);

    return refresh;
}

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
            '<p>' + esc(quantityValue(request.quantity)) + ' ' + esc(request.unit) + ' · operação #' + request.trade_id + ' · cotações até ' + esc(formatDateTime(request.quotation_expires_at)) + '</p></div></div>' +
            (minhaCotacao(request)
                ? '<p class="quote-status">Sua cotação de <strong>' + money(minhaCotacao(request).amount) + ' FRUSD</strong> foi enviada e aguarda decisão do destinatário. Você pode revisá-la enquanto ela não for escolhida.</p>'
                : '') +
            '<form class="form-grid" data-freight-form="' + request.id + '" data-offer="' + (minhaCotacao(request)?.id || '') + '">' + quoteFields(request) +
            '<button class="button button-small" type="submit">' + (minhaCotacao(request) ? 'Atualizar cotação' : 'Enviar cotação') + '</button><p class="form-message" data-form-message></p></form></article>';
    }).join('');

    bindUsdMasks(target);
    target.querySelectorAll('[data-freight-form]').forEach(function (form) {
        const refresh = bindQuoteDates(form);

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            const message = form.querySelector('[data-form-message]');
            const button = form.querySelector('[type="submit"]');
            const fields = new FormData(form);
            message.textContent = '';

            const resultado = transitLabel(fields.get('pickup_at'), fields.get('estimated_delivery_at'));
            if (resultado.invalid) {
                message.textContent = resultado.text + '.';
                refresh();

                return;
            }

            button.disabled = true;
            try {
                const existente = form.dataset.offer;
                await api(existente ? '/shipping-offers/' + existente : '/shipping-requests/' + form.dataset.freightForm + '/offers', {
                    method: existente ? 'PATCH' : 'POST',
                    body: JSON.stringify({
                        amount: normalizeUsd(fields.get('amount')),
                        pickup_at: new Date(fields.get('pickup_at')).toISOString(),
                        estimated_delivery_at: new Date(fields.get('estimated_delivery_at')).toISOString(),
                    }),
                });
                toast(form.dataset.offer ? 'Cotação atualizada.' : 'Cotação enviada.');
                renderFreights();
            } catch (error) {
                message.textContent = error.message;
                button.disabled = false;
            }
        });
    });
}
