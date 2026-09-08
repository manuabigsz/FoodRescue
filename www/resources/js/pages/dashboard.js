import { api } from '../core/api.js';
import { esc, money, quantityLabel, reputationLabel } from '../core/format.js';
import { roleLabels, statusLabels } from '../core/labels.js';
import { currentRole } from '../core/session.js';
import { state } from '../core/state.js';
import { main, setPage } from '../core/ui.js';
import { tradeTitle } from './tracking.js';

export const dashboardViews = {
    producer: { cta: ['+ Publicar excedente', '#/publicar'], metrics: producerMetrics },
    buyer: { cta: ['Encontrar excedentes', '#/catalogo'], metrics: buyerMetrics },
    carrier: { cta: ['Ver rotas abertas', '#/fretes'], metrics: carrierMetrics },
    ngo: { cta: ['Encontrar doações', '#/catalogo'], metrics: ngoMetrics },
};

export function producerMetrics(data) {
    const reputation = reputationLabel(data.summary.ratings);

    return [
        ['Excedentes ativos', data.summary.active_surplus, 'abertos ou reservados'],
        ['Receita recuperada', money(data.summary.recovered_revenue) + ' FRUSD', 'líquida da taxa do protocolo'],
        ['Alimento destinado', quantityLabel(data.quantities.total_destined), 'venda e doação concluídas'],
        ['Operações comerciais', data.summary.commercial_operations, data.summary.donation_operations + ' doações concluídas'],
        ['Reputação', reputation[0], reputation[1]],
    ];
}

export function buyerMetrics(data) {
    const reputation = reputationLabel(data.summary.ratings);

    return [
        ['Compras concluídas', data.summary.completed_purchases, 'operações liquidadas'],
        ['Total desembolsado', money(data.summary.total_spend) + ' FRUSD', 'produto e frete'],
        ['Volume comprado', quantityLabel(data.quantities.purchased), 'em operações concluídas'],
        ['Frete pago', money(data.summary.shipping_spend) + ' FRUSD', ''],
        ['Reputação', reputation[0], reputation[1]],
    ];
}

export function carrierMetrics(data) {
    const reputation = reputationLabel(data.summary.ratings);

    return [
        ['Entregas concluídas', data.summary.completed_deliveries, 'com cotação selecionada'],
        ['Receita em fretes', money(data.summary.freight_revenue) + ' FRUSD', ''],
        ['Volume transportado', quantityLabel(data.quantities.transported), ''],
        ['Reputação', reputation[0], reputation[1]],
    ];
}

export function ngoMetrics(data) {
    const reputation = reputationLabel(data.summary.ratings);

    return [
        ['Doações concluídas', data.summary.completed_donations, 'entregas recebidas'],
        ['Alimento resgatado', quantityLabel(data.quantities.rescued), ''],
        ['Proofs confirmados', data.summary.rescue_proofs, 'registrados on-chain'],
        ['Frete custeado', money(data.summary.shipping_spend) + ' FRUSD', ''],
        ['Reputação', reputation[0], reputation[1]],
    ];
}

export async function renderDashboard() {
    const role = currentRole();
    if (state.user?.roles?.includes('admin')) {
        location.hash = '#/admin';

        return;
    }
    if (!state.token || !role) {
        setPage(
            '<div class="page"><div class="shell"><div class="empty-state"><h2>' + (state.token ? 'Seu papel não tem painel operacional' : 'Entre para ver seu painel') + '</h2>' +
            '<p>' + (state.token ? 'Os painéis existem para produtor, comprador, transportadora e ONG.' : 'O painel usa os dados reais da sua conta.') + '</p>' +
            (state.token ? '' : '<button class="button" type="button" data-open-auth>Entrar na conta</button>') + '</div></div></div>',
            'Meu painel',
        );

        return;
    }

    state.dashboardRole = role;
    const view = dashboardViews[role];
    setPage(
        '<div class="page"><div class="shell"><div class="page-head"><div><span class="eyebrow">VISÃO OPERACIONAL</span><h1>Painel ' + roleLabels[role] + '</h1><p>Números da sua conta, apurados pela API a partir das operações concluídas.</p></div><a class="button" href="' + view.cta[1] + '">' + view.cta[0] + '</a></div>' +
        '<div class="dashboard-layout"><aside class="sidebar"><div class="sidebar-user"><strong>' + esc(state.user.name) + '</strong><small>' + roleLabels[role] + '</small></div>' +
        '<nav><a class="active" href="#/dashboard">⌂ Visão geral</a><a href="#/acompanhamento">◫ Operações</a>' +
        (role === 'carrier' ? '<a href="#/fretes">↗ Rotas abertas</a>' : '<a href="#/catalogo">◇ Excedentes</a>') +
        (role === 'producer' ? '<a href="#/meus-lotes">◒ Meus lotes</a>' : '') +
        '<a href="#/reputacao">◇ Reputação</a><a href="#/perfil">⚙ Perfil</a><a href="#/rede">⛓ Rede Solana</a></nav></aside>' +
        '<section class="dashboard-main"><div class="metric-grid" data-metrics><div class="skeleton" style="min-height:120px"></div><div class="skeleton" style="min-height:120px"></div><div class="skeleton" style="min-height:120px"></div><div class="skeleton" style="min-height:120px"></div></div>' +
        '<div class="panel-grid"><article class="panel"><h2>Operações por etapa</h2><div class="status-list" data-statuses></div></article>' +
        '<article class="panel"><h2>Operações recentes</h2><div class="activity-list" data-recent></div></article></div></section></div></div></div>',
        'Painel ' + roleLabels[role],
    );

    try {
        const data = await api('/dashboard/' + role);
        paintMetrics(view.metrics(data));
        paintStatusList(data.trade_statuses);
    } catch (error) {
        document.querySelector('[data-metrics]').innerHTML = '<div class="empty-state" style="grid-column:1/-1"><h2>Não foi possível carregar o painel</h2><p>' + esc(error.message) + '</p></div>';
        document.querySelector('[data-statuses]').innerHTML = '';
    }

    paintRecentTrades();
}

export function paintMetrics(metrics) {
    const target = document.querySelector('[data-metrics]');
    if (!target) return;
    target.innerHTML = metrics.map(function (metric) {
        return '<article class="metric-card"><small>' + esc(metric[0]) + '</small><strong>' + esc(metric[1]) + '</strong><span>' + esc(metric[2]) + '</span></article>';
    }).join('');
}

export function paintStatusList(statuses) {
    const target = document.querySelector('[data-statuses]');
    if (!target) return;
    const entries = Object.entries(statuses || {});
    if (!entries.length) {
        target.innerHTML = '<p class="footer-note">Nenhuma operação registrada ainda.</p>';

        return;
    }
    const highest = Math.max(...entries.map(function (entry) { return entry[1]; }));
    target.innerHTML = entries.map(function (entry) {
        const width = Math.round((entry[1] / highest) * 100);

        return '<div class="status-row"><span>' + esc(statusLabels[entry[0]] || entry[0]) + '</span><div class="status-bar"><i style="width:' + width + '%"></i></div><strong>' + entry[1] + '</strong></div>';
    }).join('');
}

export async function paintRecentTrades() {
    const target = document.querySelector('[data-recent]');
    if (!target) return;
    try {
        const trades = await api('/trades', { query: { per_page: 5 } });
        if (!trades.length) {
            target.innerHTML = '<p class="footer-note">Nenhuma operação ainda. Comece pelo catálogo de excedentes.</p>';

            return;
        }
        target.innerHTML = trades.map(function (trade) {
            return '<a class="activity-item" href="#/acompanhamento?id=' + trade.id + '"><span class="activity-icon">' + (trade.is_donation ? '♡' : '◎') + '</span>' +
                '<div><strong>' + esc(tradeTitle(trade)) + '</strong><small>' + esc(statusLabels[trade.status] || trade.status) + '</small></div>' +
                '<small>#' + trade.id + '</small></a>';
        }).join('');
    } catch (error) {
        target.innerHTML = '<p class="footer-note">' + esc(error.message) + '</p>';
    }
}
