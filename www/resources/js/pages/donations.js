import { api } from '../core/api.js';
import { config } from '../core/config.js';
import { esc, formatDateTime } from '../core/format.js';
import { statusLabels } from '../core/labels.js';
import { state } from '../core/state.js';
import { howCard, setPage, solscanAddress } from '../core/ui.js';

export async function renderDonations() {
    setPage(
        '<div class="page"><div class="shell"><section class="donation-hero"><div class="donation-copy"><span class="eyebrow eyebrow-light">IMPACTO COMPROVADO</span><h1>Resgatar é dar destino — e deixar prova.</h1><p>ONGs aceitam lotes elegíveis, coordenam o transporte e registram a destinação dos alimentos. O Proof of Rescue torna cada entrega verificável.</p><div><a class="button button-secondary" href="#/catalogo">Ver lotes para doação →</a></div></div>' +
        '<div data-proof><div class="skeleton" style="min-height:280px"></div></div></section>' +
        '<section class="section"><div class="section-head"><div><span class="eyebrow">FLUXO DE DOAÇÃO</span><h2>Da oferta ao Proof of Rescue.</h2></div><p>O estado <strong>proof_pending</strong> garante que uma doação entregue só seja concluída após a comprovação pela organização beneficiária.</p></div>' +
        '<div class="how-grid">' + howCard('01', 'Lote elegível', 'O produtor marca o excedente como disponível para doação.') + howCard('02', 'ONG aceita', 'A organização assume o recebimento e define a logística.') + howCard('03', 'Entrega', 'A carga percorre os estados funded, in_transit e delivered.') + howCard('04', 'Proof', 'A ONG confirma o resgate e a operação passa a completed.') + '</div></section></div></div>',
        'Doações e Proof of Rescue',
    );
    paintProofCard();
}

export async function paintProofCard() {
    const target = document.querySelector('[data-proof]');
    if (!target) return;

    if (!state.token) {
        target.innerHTML = '<article class="proof-card"><div class="proof-seal">✓</div><small>PROOF OF RESCUE</small><h2>Prova on-chain de destinação</h2>' +
            '<p>Cada doação entregue gera um registro assinado pelo produtor e pela organização beneficiária, gravado no programa FoodRescue.</p>' +
            '<p class="footer-note">Entre na sua conta para ver as provas das suas doações.</p>' +
            '<a class="button button-ghost button-small" href="' + solscanAddress(config.programId) + '" target="_blank" rel="noopener">Ver o programa no Solscan ↗</a></article>';

        return;
    }

    let donations = [];
    try {
        donations = await api('/trades', { query: { is_donation: 1, per_page: 10 } });
    } catch (_) {
        donations = [];
    }

    const proven = donations.find(function (trade) { return trade.rescue_proof?.confirmed_at; });
    if (!proven) {
        const pending = donations[0];
        target.innerHTML = '<article class="proof-card"><div class="proof-seal">◷</div><small>PROOF OF RESCUE</small>' +
            '<h2>' + (pending ? 'Doação #' + pending.id + ' em andamento' : 'Nenhuma doação registrada') + '</h2>' +
            '<p>' + (pending
                ? 'Estado atual: ' + esc(statusLabels[pending.status] || pending.status) + '. A prova é emitida quando a entrega for confirmada e comprovada.'
                : 'Aceite um lote elegível para doação e a prova aparecerá aqui quando o resgate for confirmado.') + '</p>' +
            (pending ? '<a class="button button-ghost button-small" href="#/acompanhamento?id=' + pending.id + '">Acompanhar operação</a>' : '<a class="button button-ghost button-small" href="#/catalogo">Ver lotes para doação</a>') +
            '</article>';

        return;
    }

    const proof = proven.rescue_proof;
    target.innerHTML = '<article class="proof-card"><div class="proof-seal">✓</div><small>PROOF OF RESCUE</small><h2>Resgate #' + proven.id + '</h2>' +
        '<p>Entrega social confirmada e registrada na Solana ' + esc(config.network) + '.</p><dl>' +
        '<div><dt>Alimento resgatado</dt><dd>' + esc((proven.surplus_lot?.quantity || '—') + ' ' + (proven.surplus_lot?.unit || '')) + '</dd></div>' +
        '<div><dt>Lote</dt><dd>' + esc(proven.surplus_lot?.product?.name || '—') + '</dd></div>' +
        '<div><dt>Confirmado em</dt><dd>' + esc(formatDateTime(proof.confirmed_at)) + '</dd></div>' +
        '<div><dt>Status</dt><dd>' + esc(statusLabels[proven.status] || proven.status) + '</dd></div></dl>' +
        '<a class="button button-ghost button-small" href="' + solscanAddress(proof.proof_pda || config.programId) + '" target="_blank" rel="noopener">Ver registro no Solscan ↗</a></article>';
}
