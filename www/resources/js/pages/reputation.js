import { openAuthOrDashboard } from '../auth/modal.js';
import { api } from '../core/api.js';
import { esc, formatDateTime, starBar } from '../core/format.js';
import { state } from '../core/state.js';
import { setPage } from '../core/ui.js';

export async function renderReputation() {
    const requested = Number(new URLSearchParams(location.hash.split('?')[1] || '').get('user'));
    setPage(
        '<div class="page"><div class="shell"><div class="page-head"><div><span class="eyebrow">REPUTAÇÃO</span><h1>Avaliações recebidas</h1><p>Cada operação concluída permite que as partes se avaliem. A média é pública para quem participa da rede.</p></div><a class="button button-ghost" href="#/dashboard">← Voltar ao painel</a></div>' +
        '<div data-reputation><div class="skeleton" style="min-height:200px"></div></div></div></div>',
        'Reputação',
    );
    const target = document.querySelector('[data-reputation]');
    if (!target) return;

    if (!state.token) {
        target.innerHTML = '<div class="empty-state"><h2>Entre para ver avaliações</h2><button class="button" type="button" data-open-auth>Entrar na conta</button></div>';
        target.querySelector('[data-open-auth]').addEventListener('click', function () { openAuthOrDashboard('login'); });

        return;
    }

    const userId = Number.isInteger(requested) && requested > 0 ? requested : state.user.id;
    let reputation;
    let ratings;
    try {
        [reputation, ratings] = await Promise.all([
            api('/users/' + userId + '/reputation'),
            api('/users/' + userId + '/ratings'),
        ]);
    } catch (error) {
        target.innerHTML = '<div class="empty-state"><h2>Não foi possível carregar</h2><p>' + esc(error.message) + '</p></div>';

        return;
    }

    const own = userId === state.user.id;
    target.innerHTML =
        '<div class="metric-grid">' +
        '<article class="metric-card"><small>' + (own ? 'Sua média' : 'Média') + '</small><strong>' +
            (reputation.count ? starBar(reputation.average) + ' ' + esc(new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(reputation.average)) : 'Sem nota') +
        '</strong><span>' + reputation.count + (reputation.count === 1 ? ' avaliação' : ' avaliações') + '</span></article></div>' +
        (ratings.length
            ? '<article class="panel"><h2>Histórico</h2><div class="activity-list">' + ratings.map(function (rating) {
                return '<div class="activity-item"><span class="activity-icon">' + rating.rating + '</span>' +
                    '<div><strong>' + esc(rating.reviewer?.name || 'Participante') + '</strong>' +
                    '<small>' + (rating.comment ? esc(rating.comment) : 'Sem comentário') + '</small></div>' +
                    '<small>' + esc(formatDateTime(rating.created_at)) + '</small></div>';
            }).join('') + '</div></article>'
            : '<div class="empty-state"><h2>Nenhuma avaliação ainda</h2><p>' + (own ? 'Conclua operações para receber avaliações das contrapartes.' : 'Este participante ainda não foi avaliado.') + '</p></div>');
}
