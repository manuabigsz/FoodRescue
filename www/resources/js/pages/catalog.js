import { openAuth, openAuthOrDashboard } from '../auth/modal.js';
import { api } from '../core/api.js';
import { deadlineLabel, esc, money, quantityValue } from '../core/format.js';
import { currentRole } from '../core/session.js';
import { state } from '../core/state.js';
import { selectOptions, setPage, toast } from '../core/ui.js';
import { selectedTradeId } from './tracking.js';

export async function renderCatalog() {
    /** A ONG só pode aceitar lotes elegíveis; o filtro já entra ligado até ela mexer. */
    if (!state.catalogFilterTouched && currentRole() === 'ngo') state.catalogFilters.donation_eligible = '1';

    const filters = state.catalogFilters;
    setPage(
        '<div class="page"><div class="shell"><div class="page-head"><div><span class="eyebrow">MARKETPLACE</span><h1>Excedentes disponíveis</h1><p>Alimentos próprios para consumo, com prazo, origem e qualidade informados pelo produtor.</p></div><button class="button" type="button" data-new-lot>+ Publicar excedente</button></div>' +
        '<div class="toolbar"><div class="field"><label for="search">Buscar produto ou cidade</label><input id="search" type="search" placeholder="Ex.: tomate ou Campinas" value="' + esc(filters.search) + '"></div>' +
        '<div class="field"><label for="donation-filter">Finalidade</label><select id="donation-filter">' + selectOptions([['', 'Todos os lotes'], ['1', 'Aceita doação'], ['0', 'Somente venda']], filters.donation_eligible) + '</select></div>' +
        '<div class="field"><label for="sort">Ordenar por</label><select id="sort">' + selectOptions([['urgency', 'Mais urgente'], ['price_asc', 'Menor preço'], ['price_desc', 'Maior preço'], ['newest', 'Mais recente']], filters.sort) + '</select></div></div>' +
        '<div data-catalog-notice></div>' +
        '<div class="catalog-grid" data-catalog><div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div></div>' +
        '<div class="pagination" data-catalog-pagination></div></div></div>',
        'Catálogo de excedentes'
    );
    bindCatalogToolbar();
    await loadCatalog();
}

export async function loadCatalog() {
    const grid = document.querySelector('[data-catalog]');
    if (grid) grid.innerHTML = '<div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div>';
    const filters = state.catalogFilters;
    state.catalog = [];
    state.catalogMeta = null;
    state.catalogReason = null;
    state.catalogError = false;
    if (!state.token) {
        state.catalogReason = 'A listagem de excedentes exige autenticação.';
    } else {
        try {
            const payload = await api('/surplus', {
                raw: true,
                query: { search: filters.search, sort: filters.sort, donation_eligible: filters.donation_eligible, per_page: filters.per_page, page: filters.page },
            });
            state.catalog = payload?.data || [];
            state.catalogMeta = payload?.meta || null;
        } catch (error) {
            state.catalogReason = error.message;
            state.catalogError = true;
        }
    }
    paintCatalogNotice();
    paintCatalog();
    paintCatalogPagination();
}

export function paintCatalogNotice() {
    const target = document.querySelector('[data-catalog-notice]');
    if (!target) return;
    if (!state.catalogReason) {
        target.innerHTML = '';
        return;
    }
    const message = state.catalogError
        ? 'Não foi possível carregar os excedentes agora. Verifique a conexão e tente novamente.'
        : 'Entre na sua conta para consultar os excedentes reais disponíveis.';
    target.innerHTML = '<div class="catalog-alert" role="status"><span>' + message + '</span>' +
        (state.catalogError ? '<button class="button button-small button-ghost" type="button" data-retry-catalog>Tentar novamente</button>' : '<button class="button button-small" type="button" data-open-auth>Entrar na conta</button>') + '</div>';
    target.querySelector('[data-open-auth]')?.addEventListener('click', function () { openAuthOrDashboard('login'); });
    target.querySelector('[data-retry-catalog]')?.addEventListener('click', loadCatalog);
}

export function paintCatalogPagination() {
    const target = document.querySelector('[data-catalog-pagination]');
    if (!target) return;
    const meta = state.catalogMeta;
    if (!meta || meta.last_page <= 1) {
        target.innerHTML = '';
        return;
    }
    target.innerHTML = '<button class="button button-ghost button-small" type="button" data-page="' + (meta.current_page - 1) + '"' + (meta.current_page <= 1 ? ' disabled' : '') + '>← Anterior</button>' +
        '<span>Página ' + meta.current_page + ' de ' + meta.last_page + ' · ' + meta.total + ' lotes</span>' +
        '<button class="button button-ghost button-small" type="button" data-page="' + (meta.current_page + 1) + '"' + (meta.current_page >= meta.last_page ? ' disabled' : '') + '>Próxima →</button>';
    target.querySelectorAll('[data-page]').forEach(function (button) {
        button.addEventListener('click', function () {
            state.catalogFilters.page = Number(button.dataset.page);
            loadCatalog();
        });
    });
}

export function paintCatalog() {
    const grid = document.querySelector('[data-catalog]');
    const lots = state.catalog;
    if (!grid) return;
    if (!lots.length) {
        grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1"><h2>Nenhum excedente encontrado</h2><p>' +
            (state.catalogFilters.search ? 'Nada corresponde a “' + esc(state.catalogFilters.search) + '”. Tente outro termo ou remova os filtros.' : 'Tente remover alguns filtros ou volte mais tarde.') +
            '</p></div>';
        return;
    }
    grid.innerHTML = lots.map(function (lot, index) {
        const product = lot.product?.name || lot.agricultural_product?.name || 'Produto agrícola';
        const theme = lot.theme || ['tomato', '', 'green', 'purple'][index % 4];
        const ownLot = Number(lot.producer?.id) === Number(state.user?.id);
        const actions = ownLot
            ? '<span class="footer-note">Este lote pertence a você.</span>'
            : '<button class="button button-small" type="button" data-buy="' + esc(lot.id) + '">Comprar agora</button>' + (lot.donation_eligible ? '<button class="button button-ghost button-small" type="button" data-donate="' + esc(lot.id) + '">Doação</button>' : '');
        return '<article class="lot-card" data-lot-card>' +
            '<div class="lot-visual ' + theme + '"><span class="produce-shape" aria-hidden="true"></span><span class="lot-tag">' + esc(lot.quality_grade?.name || 'Qualidade verificada') + '</span></div>' +
            '<div class="lot-body"><div class="lot-title"><h3>' + esc(product) + '</h3><div class="lot-price">' + money(lot.asking_price) + ' FRUSD<small>valor total do lote</small></div></div>' +
            '<div class="lot-meta"><span>◉ ' + esc(lot.origin?.city || 'Origem') + ', ' + esc(lot.origin?.state || 'BR') + '</span><span>◷ ' + esc(deadlineLabel(lot.available_until)) + '</span><span>' + esc(quantityValue(lot.quantity)) + ' ' + esc(lot.unit) + '</span></div>' +
            '<div class="lot-actions">' + actions + '</div></div></article>';
    }).join('');
    bindLotActions();
}

export function bindCatalogToolbar() {
    let searchTimer = null;
    document.querySelector('#search')?.addEventListener('input', function (event) {
        const term = event.target.value.trim();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
            if (term === state.catalogFilters.search) return;
            state.catalogFilters.search = term;
            state.catalogFilterTouched = true;
            state.catalogFilters.page = 1;
            loadCatalog();
        }, 350);
    });
    document.querySelector('#donation-filter')?.addEventListener('change', function (event) {
        state.catalogFilters.donation_eligible = event.target.value;
        state.catalogFilterTouched = true;
        state.catalogFilters.page = 1;
        loadCatalog();
    });
    document.querySelector('#sort')?.addEventListener('change', function (event) {
        state.catalogFilters.sort = event.target.value;
        state.catalogFilters.page = 1;
        loadCatalog();
    });
}

export function bindLotActions() {
    document.querySelectorAll('[data-buy]').forEach(function (button) {
        button.addEventListener('click', function () { createTrade(button.dataset.buy, false); });
    });
    document.querySelectorAll('[data-donate]').forEach(function (button) {
        button.addEventListener('click', function () { createTrade(button.dataset.donate, true); });
    });
}

export async function createTrade(lotId, donation) {
    if (!state.token) {
        openAuth(donation ? 'register' : 'login');
        toast('Conecte sua carteira e entre para continuar.');
        return;
    }
    try {
        const path = donation ? '/surplus/' + lotId + '/donations/accept' : '/surplus/' + lotId + '/buy-now';
        const trade = await api(path, { method: 'POST', body: '{}' });
        state.selectedTradeId = trade.id;
        toast(donation ? 'Doação aceita. Agora defina a logística.' : 'Compra reservada. Agora defina o transporte.');
        location.hash = '#/acompanhamento?id=' + trade.id;
    } catch (error) {
        toast(error.message, 'error');
    }
}
