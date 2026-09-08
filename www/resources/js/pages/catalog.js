import { openAuth, openAuthOrDashboard } from '../auth/modal.js';
import { api } from '../core/api.js';
import { deadlineLabel, esc, money } from '../core/format.js';
import { currentRole } from '../core/session.js';
import { state } from '../core/state.js';
import { selectOptions, setPage, toast } from '../core/ui.js';
import { selectedTradeId } from './tracking.js';

export const sampleLots = [
    { id: 1042, product: { name: 'Tomate italiano' }, quality_grade: { name: 'Tipo B' }, quantity: '680', unit: 'kg', origin: { city: 'Mogi das Cruzes', state: 'SP' }, asking_price: '2.35', available_until: new Date(Date.now() + 86400000).toISOString(), donation_eligible: true, theme: 'tomato' },
    { id: 1041, product: { name: 'Banana-prata' }, quality_grade: { name: 'Tipo A' }, quantity: '1.2', unit: 't', origin: { city: 'Registro', state: 'SP' }, asking_price: '1.80', available_until: new Date(Date.now() + 172800000).toISOString(), donation_eligible: false, theme: '' },
    { id: 1039, product: { name: 'Couve manteiga' }, quality_grade: { name: 'Tipo B' }, quantity: '240', unit: 'kg', origin: { city: 'Ibiúna', state: 'SP' }, asking_price: '1.15', available_until: new Date(Date.now() + 21600000).toISOString(), donation_eligible: true, theme: 'green' },
    { id: 1037, product: { name: 'Batata-doce' }, quality_grade: { name: 'Tipo A' }, quantity: '830', unit: 'kg', origin: { city: 'Piedade', state: 'SP' }, asking_price: '1.65', available_until: new Date(Date.now() + 259200000).toISOString(), donation_eligible: true, theme: 'purple' },
    { id: 1035, product: { name: 'Cenoura' }, quality_grade: { name: 'Tipo B' }, quantity: '420', unit: 'kg', origin: { city: 'São Gotardo', state: 'MG' }, asking_price: '1.32', available_until: new Date(Date.now() + 129600000).toISOString(), donation_eligible: false, theme: '' },
    { id: 1032, product: { name: 'Abobrinha' }, quality_grade: { name: 'Tipo A' }, quantity: '315', unit: 'kg', origin: { city: 'Atibaia', state: 'SP' }, asking_price: '1.48', available_until: new Date(Date.now() + 64800000).toISOString(), donation_eligible: false, theme: 'green' },
];

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

export function useDemoCatalog(reason) {
    state.catalog = sampleLots;
    state.catalogMeta = null;
    state.catalogIsDemo = true;
    state.catalogReason = reason;
}

export async function loadCatalog() {
    const grid = document.querySelector('[data-catalog]');
    if (grid) grid.innerHTML = '<div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div>';
    const filters = state.catalogFilters;
    if (!state.token) {
        useDemoCatalog('A listagem de excedentes exige autenticação.');
    } else {
        try {
            const payload = await api('/surplus', {
                raw: true,
                query: { search: filters.search, sort: filters.sort, donation_eligible: filters.donation_eligible, per_page: filters.per_page, page: filters.page },
            });
            state.catalog = payload?.data || [];
            state.catalogMeta = payload?.meta || null;
            state.catalogIsDemo = false;
        } catch (error) {
            useDemoCatalog(error.message);
        }
    }
    paintCatalogNotice();
    paintCatalog();
    paintCatalogPagination();
}

export function paintCatalogNotice() {
    const target = document.querySelector('[data-catalog-notice]');
    if (!target) return;
    if (!state.catalogIsDemo) {
        target.innerHTML = '';
        return;
    }
    target.innerHTML = '<div class="demo-banner"><span>Lotes de demonstração — ' + esc(state.catalogReason) + ' Comprar e doar não funciona neste modo.</span>' +
        (state.user ? '' : '<button class="button button-small" type="button" data-open-auth>Entrar na conta</button>') + '</div>';
    target.querySelector('[data-open-auth]')?.addEventListener('click', function () { openAuthOrDashboard('login'); });
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
        return '<article class="lot-card" data-lot-card>' +
            '<div class="lot-visual ' + theme + '"><span class="produce-shape" aria-hidden="true"></span><span class="lot-tag">' + esc(lot.quality_grade?.name || 'Qualidade verificada') + '</span></div>' +
            '<div class="lot-body"><div class="lot-title"><h3>' + esc(product) + '</h3><div class="lot-price">' + money(lot.asking_price) + ' FRUSD<small>por ' + esc(lot.unit) + '</small></div></div>' +
            '<div class="lot-meta"><span>◉ ' + esc(lot.origin?.city || 'Origem') + ', ' + esc(lot.origin?.state || 'BR') + '</span><span>◷ ' + esc(deadlineLabel(lot.available_until)) + '</span><span>' + esc(lot.quantity) + ' ' + esc(lot.unit) + '</span></div>' +
            '<div class="lot-actions"><button class="button button-small" type="button" data-buy="' + esc(lot.id) + '">Comprar agora</button>' + (lot.donation_eligible ? '<button class="button button-ghost button-small" type="button" data-donate="' + esc(lot.id) + '">Doação</button>' : '') + '</div></div></article>';
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
    if (state.catalogIsDemo) {
        toast('Estes lotes são de demonstração e não existem na API.', 'error');
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
