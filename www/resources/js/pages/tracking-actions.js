import { api } from '../core/api.js';
import { esc, formatDateTime, money, short } from '../core/format.js';
import { stateOptions } from '../core/form-fields.js';
import { currentRole } from '../core/session.js';
import {
    connectedWalletFor,
    derivedAddress,
    pendingCoSigners,
    signAndSend,
    walletAvailable,
    walletErrorMessage,
} from '../core/solana.js';
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

export function onChainEscrow(trade) {
    return Boolean(trade.blockchain?.trade_pda);
}

export function tradeActions(trade) {
    const actions = [];
    const recipient = isRecipientOf(trade);
    const producer = isProducerOf(trade);
    const carrier = currentRole() === 'carrier';

    /**
     * Depois que a custódia existe on-chain, cada etapa de entrega precisa de uma
     * transação assinada — o backend recusa a confirmação sem assinatura. A
     * assinatura acontece na carteira do próprio ator, então a ação continua
     * sendo um botão; só o painel por trás dele muda.
     */
    const signed = onChainEscrow(trade);

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
        actions.push([signed ? 'onchain-ready' : 'ready', 'Liberar para coleta', '']);
    }
    if (trade.status === 'ready_for_pickup' && (carrier || (recipient && selfManagedShipping(trade)))) {
        actions.push([signed ? 'onchain-pickup' : 'pickup', 'Confirmar coleta', '']);
    }
    if (trade.status === 'in_transit' && recipient) {
        actions.push([signed ? 'onchain-delivered' : 'delivered', 'Confirmar entrega', '']);
    }
    if (trade.status === 'delivered' && signed && recipient) {
        actions.push(['onchain-settlement', 'Liquidar a operação', '']);
    }
    if (trade.is_donation && recipient && !trade.rescue_proof && ['delivered', 'proof_pending'].includes(trade.status)) {
        actions.push(['proof', 'Emitir Proof of Rescue', '']);
    }
    if (trade.is_donation && producer && trade.rescue_proof?.awaiting_producer) {
        actions.push(['proof-producer', 'Confirmar Proof of Rescue', '']);
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
        proof: proofPanel,
        'proof-producer': proofProducerPanel,
        'onchain-ready': deliveryPanel('Liberar para coleta', 'O produtor assina a transição da custódia para “Pronto para coleta”.', 'ready-for-pickup', 'produtor'),
        'onchain-pickup': deliveryPanel('Confirmar a coleta', 'Registra a retirada da carga on-chain e move a operação para “Em trânsito”.', 'pickup', 'transportadora (ou destinatário, no transporte próprio)'),
        'onchain-delivered': deliveryPanel('Confirmar a entrega', 'O destinatário assina o recebimento da carga. Em doações, o próximo passo é o Proof of Rescue.', 'delivered', 'destinatário'),
        'onchain-settlement': settlementPanel,
    };
    (panels[action] || function () {})(trade, target);
}

export function destinationFields() {
    return '<div class="field"><label>Endereço de destino</label><input name="destination_address" maxlength="255" required></div>' +
        '<div class="field-row"><div class="field"><label>Cidade</label><input name="destination_city" maxlength="120" required></div>' +
        '<div class="field"><label>Estado</label><select name="destination_state" required>' + stateOptions() + '</select></div></div>';
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

/**
 * Executa uma sequência assinada, mostrando o andamento no próprio painel. O
 * usuário precisa saber em qual das assinaturas está, porque a carteira abre um
 * popup por transação e um erro no meio deixa a operação parcialmente aplicada.
 */
export async function runOnChain(target, run) {
    const message = target.querySelector('[data-panel-message]');
    const button = target.querySelector('[data-panel-confirm]');
    if (button) button.disabled = true;
    const progress = function (texto) { if (message) message.textContent = texto; };
    progress('Abrindo a carteira…');
    try {
        await run(progress);
        state.trackingPanel = null;
        await refreshTracking(state.selectedTradeId);
    } catch (error) {
        progress(walletErrorMessage(error));
        if (button) button.disabled = false;
    }
}

function summaryGrid(rows) {
    return '<div class="details-grid">' + rows.filter(function (row) { return Boolean(row[1]); }).map(function (row) {
        return '<div class="detail"><small>' + esc(row[0]) + '</small><strong title="' + esc(String(row[1])) + '">' + esc(short(String(row[1]), 8, 6)) + '</strong></div>';
    }).join('') + '</div>';
}

/**
 * Painel de uma etapa que precisa de assinatura. Antes de oferecer o botão,
 * confere se a carteira do ator dá conta da transação sozinha: instruções com
 * mais de um signatário — como o cancelamento com escrow financiado, que exige
 * comprador e produtor — não saem por uma extensão só, e é melhor dizer isso
 * agora do que depois de o usuário assinar.
 */
export async function signaturePanel(target, options) {
    const corpo = (options.descricaoHtml ? options.descricao : '<p>' + options.descricao + '</p>') + summaryGrid(options.resumo) + (options.extra || '');

    if (!walletAvailable()) {
        target.innerHTML = panelShell(options.titulo, corpo +
            '<p class="footer-note">Nenhuma carteira Solana foi encontrada no navegador. Instale a Phantom, conecte a carteira cadastrada e recarregue a página para assinar esta etapa.</p>');

        return;
    }

    let coSigners = [];
    try {
        coSigners = await pendingCoSigners(options.preparation, options.instruction, options.wallet);
    } catch (error) {
        target.innerHTML = panelShell(options.titulo, corpo + '<p class="footer-note">' + esc(error.message) + '</p>');

        return;
    }

    if (coSigners.length) {
        target.innerHTML = panelShell(options.titulo, corpo +
            '<p class="footer-note">Esta transação exige também a assinatura de ' + esc(coSigners.map(function (item) {
                return item.name + ' (' + short(item.pubkey, 6, 6) + ')';
            }).join(', ')) + '. Uma carteira sozinha não consegue enviá-la pelo navegador.</p>');

        return;
    }

    target.innerHTML = panelShell(options.titulo, corpo +
        '<button class="button button-small" type="button" data-panel-confirm>' + esc(options.botao) + '</button>');

    if (options.onRender) options.onRender(target);

    target.querySelector('[data-panel-confirm]').addEventListener('click', function () {
        runOnChain(target, async function (progress) {
            const carteira = await connectedWalletFor(options.wallet, options.papel);
            await options.run(progress, carteira);
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

    /** A custódia pode já existir se o pagamento parou entre as duas assinaturas. */
    const jaInicializado = onChainEscrow(trade);
    const papel = trade.is_donation ? 'instituição social' : 'comprador';
    const frete = Number(trade.shipping_amount || 0) > 0 ? money(trade.shipping_amount) + ' FRUSD' : 'Sem frete';
    const descricaoPagamento = jaInicializado
        ? '<div class="payment-intro"><strong>Seu pagamento está quase concluído.</strong><p>A reserva já está protegida. Falta apenas confirmar a transferência do valor para liberar o próximo passo da operação.</p></div>'
        : '<div class="payment-intro"><strong>Seu pagamento ficará protegido até a entrega.</strong><p>O valor será reservado em custódia e só será distribuído quando a operação cumprir as etapas combinadas.</p></div>' +
            '<div class="payment-steps"><span><b>1</b> Criar a proteção</span><span><b>2</b> Confirmar o pagamento</span></div>';

    await signaturePanel(target, {
        titulo: 'Pagamento em custódia',
        descricao: descricaoPagamento + '<p class="footer-note">A confirmação será aberta na sua carteira. Você não precisa compartilhar senha ou chave privada.</p>',
        descricaoHtml: true,
        preparation: preparation,
        instruction: jaInicializado ? preparation.fund_instruction : preparation.initialize_instruction,
        wallet: preparation.wallets?.buyer,
        papel: papel,
        botao: jaInicializado ? 'Assinar o pagamento' : 'Assinar e pagar',
        resumo: [
            ['Total a pagar', money(trade.buyer_total) + ' FRUSD'],
            ['Valor do alimento', money(trade.product_amount) + ' FRUSD'],
            ['Frete', frete],
            ['Pagamento até', formatDateTime(trade.payment_expires_at)],
        ],
        run: async function (progress, carteira) {
            if (!jaInicializado) {
                progress('1 de 2 — confirme a criação da custódia na carteira…');
                const signature = await signAndSend(preparation, preparation.initialize_instruction, carteira);
                const tradePda = (await derivedAddress(preparation, 'trade_pda')).toBase58();
                const vault = (await derivedAddress(preparation, 'vault_token_account')).toBase58();
                progress('Custódia criada. Registrando no FoodRescue…');
                await api('/trades/' + trade.id + '/blockchain/initialize/confirm', {
                    method: 'POST',
                    body: JSON.stringify({ signature: signature, trade_pda: tradePda, vault_token_account: vault }),
                });
            }

            progress((jaInicializado ? '' : '2 de 2 — ') + 'confirme o pagamento na carteira…');
            const funding = await signAndSend(preparation, preparation.fund_instruction, carteira);
            progress('Pagamento enviado. Registrando no FoodRescue…');
            await api('/trades/' + trade.id + '/blockchain/funding/confirm', {
                method: 'POST',
                body: JSON.stringify({ signature: funding }),
            });
        },
    });
}

/** Etapas operacionais (coleta, trânsito, entrega): uma assinatura do ator da vez. */
export function deliveryPanel(titulo, descricao, operacao, papel) {
    return async function (trade, target) {
        target.innerHTML = panelShell(titulo, '<div class="skeleton" style="min-height:60px"></div>');
        let preparation;
        try {
            preparation = await api('/trades/' + trade.id + '/delivery/' + operacao + '/prepare', { method: 'POST', body: '{}' });
        } catch (error) {
            target.innerHTML = panelShell(titulo, '<p>Não foi possível preparar a instrução: ' + esc(error.message) + '</p>');

            return;
        }

        const liberacaoDoProdutor = operacao === 'ready-for-pickup';
        const confirmacaoDaColeta = operacao === 'pickup';
        const confirmacaoDaEntrega = operacao === 'delivered';
        await signaturePanel(target, {
            titulo: titulo,
            descricao: liberacaoDoProdutor
                ? 'Confirme que o lote está pronto, separado e disponível para a coleta. Depois desta confirmação, a operação avança e a transportadora poderá seguir com a retirada.'
                : confirmacaoDaColeta
                    ? 'Confirme que a coleta foi realizada e que a carga está sob sua responsabilidade. Depois desta confirmação, o acompanhamento seguirá para o transporte.'
                : confirmacaoDaEntrega
                    ? 'Confirme que você recebeu a carga e que o produto chegou ao destino. Depois desta confirmação, a operação poderá seguir para a etapa final.'
                : descricao + ' Quem assina: <strong>' + esc(papel) + '</strong>.',
            preparation: preparation,
            instruction: preparation.instruction,
            wallet: preparation.wallet,
            papel: papel,
            botao: liberacaoDoProdutor ? 'Confirmar liberação' : confirmacaoDaColeta ? 'Confirmar coleta' : confirmacaoDaEntrega ? 'Confirmar entrega' : 'Assinar na carteira',
            resumo: liberacaoDoProdutor || confirmacaoDaColeta || confirmacaoDaEntrega ? [] : [
                ['Programa', preparation.program_id],
                ['Custódia', preparation.trade_pda],
                ['Carteira que assina', preparation.wallet],
            ],
            run: async function (progress, carteira) {
                progress('Confirme a transação na carteira…');
                const signature = await signAndSend(preparation, preparation.instruction, carteira);
                progress('Transação confirmada. Registrando no FoodRescue…');
                await api('/trades/' + trade.id + '/delivery/' + operacao, {
                    method: 'POST',
                    body: JSON.stringify({ signature: signature }),
                });
            },
        });
    };
}

export async function settlementPanel(trade, target) {
    const titulo = 'Liquidar a operação';
    target.innerHTML = panelShell(titulo, '<div class="skeleton" style="min-height:80px"></div>');
    let preparation;
    try {
        preparation = await api('/trades/' + trade.id + '/blockchain/settlement/prepare', { method: 'POST', body: '{}' });
    } catch (error) {
        target.innerHTML = panelShell(titulo, '<p>Não foi possível preparar a liquidação: ' + esc(error.message) + '</p>');

        return;
    }

    await signaturePanel(target, {
        titulo: titulo,
        descricaoHtml: true,
        descricao: '<div class="settlement-intro"><span class="eyebrow">ÚLTIMA ETAPA</span><strong>A entrega foi confirmada.</strong><p>Ao finalizar esta operação, os valores protegidos serão liberados automaticamente para cada participante.</p></div>' +
            '<div class="settlement-release-notice"><span aria-hidden="true">✓</span><div><strong>O que acontece ao confirmar?</strong><p>O produtor recebe o valor do produto, a transportadora recebe o frete quando houver e a taxa do protocolo é encaminhada à tesouraria.</p></div></div>',
        preparation: preparation,
        instruction: preparation.settle_instruction,
        wallet: preparation.wallets?.buyer,
        papel: trade.is_donation ? 'instituição social' : 'comprador',
        botao: 'Finalizar e liberar valores',
        resumo: [
            ['Valor do produto', money(trade.product_amount) + ' FRUSD'],
            ['Frete', Number(trade.shipping_amount) > 0 ? money(trade.shipping_amount) + ' FRUSD' : 'Sem frete'],
            ['Total protegido', money(trade.buyer_total) + ' FRUSD'],
        ],
        run: async function (progress, carteira) {
            progress('Confirme a liquidação na carteira…');
            const signature = await signAndSend(preparation, preparation.settle_instruction, carteira);
            progress('Liquidação confirmada. Registrando no FoodRescue…');
            await api('/trades/' + trade.id + '/blockchain/settlement/confirm', {
                method: 'POST',
                body: JSON.stringify({ signature: signature }),
            });
        },
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
    if (onChainEscrow(trade)) return onChainCancelPanel(trade, target);

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

/**
 * Proof of Rescue, primeira metade: a instituição social abre a atestação. O
 * produtor confirma depois, em transação própria — duas assinaturas na mesma
 * transação não sobrevivem a dois atores assinando em momentos diferentes.
 */
export async function proofPanel(trade, target) {
    const titulo = 'Emitir Proof of Rescue';
    target.innerHTML = panelShell(titulo, '<div class="skeleton" style="min-height:80px"></div>');
    let preparation;
    try {
        preparation = await api('/trades/' + trade.id + '/rescue-proof/prepare', { method: 'POST', body: '{}' });
    } catch (error) {
        target.innerHTML = panelShell(titulo, '<p>Não foi possível preparar a atestação: ' + esc(error.message) + '</p>');

        return;
    }

    await signaturePanel(target, {
        titulo: titulo,
        descricao: 'A atestação registra on-chain o resgate deste lote, com o hash dos dados da doação. Depois da sua assinatura, o produtor confirma e a doação é concluída.',
        preparation: preparation,
        instruction: preparation.instruction,
        wallet: preparation.wallets?.ngo,
        papel: 'instituição social',
        botao: 'Assinar a atestação',
        resumo: [
            ['Programa', preparation.program_id],
            ['Hash dos dados', preparation.metadata_hash],
            ['Produtor', preparation.wallets?.producer],
            ['Transportadora', preparation.wallets?.carrier],
        ],
        run: async function (progress, carteira) {
            progress('Confirme a atestação na carteira…');
            const signature = await signAndSend(preparation, preparation.instruction, carteira);
            const proofPda = (await derivedAddress(preparation, 'rescue_proof_pda')).toBase58();
            progress('Atestação registrada. Avisando o FoodRescue…');
            await api('/trades/' + trade.id + '/rescue-proof/confirm', {
                method: 'POST',
                body: JSON.stringify({ signature: signature, proof_pda: proofPda }),
            });
            toast('Proof of Rescue aberto. Falta a confirmação do produtor.');
        },
    });
}

/** Proof of Rescue, segunda metade: o produtor confirma o que a NGO abriu. */
export async function proofProducerPanel(trade, target) {
    const titulo = 'Confirmar Proof of Rescue';
    target.innerHTML = panelShell(titulo, '<div class="skeleton" style="min-height:80px"></div>');
    let preparation;
    try {
        preparation = await api('/trades/' + trade.id + '/rescue-proof/producer/prepare', { method: 'POST', body: '{}' });
    } catch (error) {
        target.innerHTML = panelShell(titulo, '<p>Não foi possível preparar a confirmação: ' + esc(error.message) + '</p>');

        return;
    }

    await signaturePanel(target, {
        titulo: titulo,
        descricao: 'A instituição social já registrou a atestação on-chain. Sua assinatura fecha o Proof of Rescue e conclui a doação.',
        preparation: preparation,
        instruction: preparation.instruction,
        wallet: preparation.wallet,
        papel: 'produtor',
        botao: 'Assinar a confirmação',
        resumo: [
            ['Programa', preparation.program_id],
            ['Atestação', preparation.proof_pda],
            ['Carteira que assina', preparation.wallet],
        ],
        run: async function (progress, carteira) {
            progress('Confirme a atestação na carteira…');
            const signature = await signAndSend(preparation, preparation.instruction, carteira);
            progress('Confirmação registrada. Concluindo a doação…');
            await api('/trades/' + trade.id + '/rescue-proof/producer/confirm', {
                method: 'POST',
                body: JSON.stringify({ signature: signature }),
            });
            toast('Proof of Rescue concluído.');
        },
    });
}

/**
 * Cancelamento com custódia on-chain: devolve o saldo do cofre ao pagador. Com
 * o escrow já financiado o programa exige as assinaturas do comprador e do
 * produtor na mesma transação, então o painel avisa em vez de tentar enviar.
 */
export async function onChainCancelPanel(trade, target) {
    const titulo = 'Cancelar operação';
    target.innerHTML = panelShell(titulo, '<div class="skeleton" style="min-height:80px"></div>');
    let preparation;
    try {
        preparation = await api('/trades/' + trade.id + '/blockchain/cancellation/prepare', { method: 'POST', body: '{}' });
    } catch (error) {
        target.innerHTML = panelShell(titulo, '<p>Não foi possível preparar o cancelamento: ' + esc(error.message) + '</p>');

        return;
    }

    await signaturePanel(target, {
        titulo: titulo,
        descricao: preparation.was_funded
            ? 'O escrow está financiado: o cancelamento estorna todo o saldo do cofre ao pagador e exige a assinatura do comprador e do produtor na mesma transação.'
            : 'A custódia existe mas ainda não foi financiada. O cancelamento fecha a operação on-chain e libera o lote.',
        preparation: preparation,
        instruction: preparation.cancel_instruction,
        wallet: preparation.wallets?.actor,
        papel: 'participante desta operação',
        botao: 'Assinar o cancelamento',
        resumo: [
            ['Custódia', preparation.trade_pda],
            ['Cofre', preparation.vault_token_account],
            ['Estorno', preparation.amounts?.refund],
            ['Carteira que assina', preparation.wallets?.actor],
        ],
        extra: '<div class="field"><label for="cancel-reason">Motivo (opcional)</label><input id="cancel-reason" name="reason" maxlength="500"></div>',
        run: async function (progress, carteira) {
            const reason = target.querySelector('#cancel-reason')?.value;
            progress('Confirme o cancelamento na carteira…');
            const signature = await signAndSend(preparation, preparation.cancel_instruction, carteira);
            progress('Cancelamento confirmado. Registrando no FoodRescue…');
            await api('/trades/' + trade.id + '/blockchain/cancellation/confirm', {
                method: 'POST',
                body: JSON.stringify(reason ? { signature: signature, reason: reason } : { signature: signature }),
            });
        },
    });
}
