import { openAuthOrDashboard } from '../auth/modal.js';
import { api } from '../core/api.js';
import { esc, localDateTimeValue } from '../core/format.js';
import { logisticsLabels, roleLabels, unitLabels } from '../core/labels.js';
import { currentRole } from '../core/session.js';
import { state } from '../core/state.js';
import { setPage, toast } from '../core/ui.js';

export async function loadReferenceCatalog() {
    if (state.referenceCatalog) return state.referenceCatalog;
    const [products, grades] = await Promise.all([api('/catalog/products'), api('/catalog/quality-grades')]);
    state.referenceCatalog = { products: products, grades: grades };

    return state.referenceCatalog;
}

export async function renderPublish() {
    setPage(
        '<div class="page"><div class="shell"><div class="page-head"><div><span class="eyebrow">MARKETPLACE</span><h1>Publicar excedente</h1><p>Descreva o lote, o prazo e as modalidades de logística aceitas. O preço mínimo fica visível apenas para você.</p></div><a class="button button-ghost" href="#/catalogo">← Voltar ao catálogo</a></div>' +
        '<div data-publish><div class="skeleton" style="min-height:220px"></div></div></div></div>',
        'Publicar excedente',
    );
    const target = document.querySelector('[data-publish]');
    if (!target) return;

    if (!state.token) {
        target.innerHTML = '<div class="empty-state"><h2>Entre para publicar</h2><p>Só produtores autenticados podem cadastrar excedentes.</p><button class="button" type="button" data-open-auth>Entrar na conta</button></div>';
        target.querySelector('[data-open-auth]').addEventListener('click', function () { openAuthOrDashboard('login'); });

        return;
    }

    if (currentRole() !== 'producer') {
        target.innerHTML = '<div class="empty-state"><h2>Disponível para produtores</h2><p>Sua conta está registrada como ' + esc(roleLabels[currentRole()] || 'outro papel') + '. Apenas produtores publicam excedentes.</p><a class="button" href="#/catalogo">Ver excedentes →</a></div>';

        return;
    }

    let reference;
    try {
        reference = await loadReferenceCatalog();
    } catch (error) {
        target.innerHTML = '<div class="empty-state"><h2>Não foi possível carregar o catálogo</h2><p>' + esc(error.message) + '</p></div>';

        return;
    }

    if (!reference.products.length) {
        target.innerHTML = '<div class="empty-state"><h2>Nenhum produto cadastrado</h2><p>O administrador precisa cadastrar ao menos um produto agrícola antes da primeira publicação.</p></div>';

        return;
    }

    const tomorrow = new Date(Date.now() + 86400000);
    target.innerHTML = '<form class="panel form-grid" data-publish-form>' +
        '<div class="field-row"><div class="field"><label for="product">Produto agrícola</label><select id="product" name="agricultural_product_id" required>' +
            reference.products.map(function (product) { return '<option value="' + product.id + '">' + esc(product.name) + '</option>'; }).join('') +
        '</select></div>' +
        '<div class="field"><label for="grade">Classificação de qualidade</label><select id="grade" name="quality_grade_id"><option value="">Não informar</option>' +
            reference.grades.map(function (grade) { return '<option value="' + grade.id + '">' + esc(grade.name) + '</option>'; }).join('') +
        '</select></div></div>' +
        '<div class="field-row"><div class="field"><label for="quantity">Quantidade</label><input id="quantity" name="quantity" type="number" step="0.001" min="0.001" required></div>' +
        '<div class="field"><label for="unit">Unidade</label><select id="unit" name="unit">' +
            Object.keys(unitLabels).map(function (unit) { return '<option value="' + unit + '">' + unitLabels[unit] + '</option>'; }).join('') +
        '</select></div></div>' +
        '<div class="field-row"><div class="field"><label for="asking-price">Preço pedido (FRUSD)</label><input id="asking-price" name="asking_price" type="number" step="0.000001" min="0" required></div>' +
        '<div class="field"><label for="minimum-price">Preço mínimo aceito (opcional, privado)</label><input id="minimum-price" name="minimum_price" type="number" step="0.000001" min="0"></div></div>' +
        '<div class="field-row"><div class="field"><label for="harvest">Data da colheita</label><input id="harvest" name="harvest_date" type="date" value="' + new Date().toISOString().slice(0, 10) + '" required></div>' +
        '<div class="field"><label for="available">Disponível até</label><input id="available" name="available_until" type="datetime-local" value="' + localDateTimeValue(tomorrow) + '" required></div></div>' +
        '<div class="field"><label for="address">Endereço de origem</label><input id="address" name="origin_address" maxlength="255" required></div>' +
        '<div class="field-row"><div class="field"><label for="city">Cidade</label><input id="city" name="origin_city" maxlength="120" required></div>' +
        '<div class="field"><label for="uf">Estado</label><input id="uf" name="origin_state" maxlength="80" required></div></div>' +
        '<fieldset class="field"><legend>Modalidades de logística aceitas</legend>' +
            Object.keys(logisticsLabels).map(function (mode) {
                return '<label class="check-line"><input type="checkbox" name="accepted_logistics_modes" value="' + mode + '" checked> ' + logisticsLabels[mode] + '</label>';
            }).join('') +
        '</fieldset>' +
        '<label class="check-line"><input type="checkbox" name="donation_eligible"> Aceito destinar este lote como doação a uma organização social</label>' +
        '<button class="button" type="submit">Publicar excedente</button><p class="form-message" data-form-message></p></form>';

    target.querySelector('[data-publish-form]').addEventListener('submit', handlePublish);
}

export async function handlePublish(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const message = form.querySelector('[data-form-message]');
    const button = form.querySelector('[type="submit"]');
    const fields = new FormData(form);
    const modes = fields.getAll('accepted_logistics_modes');
    message.textContent = '';

    if (!modes.length) {
        message.textContent = 'Selecione ao menos uma modalidade de logística.';

        return;
    }

    const payload = {
        agricultural_product_id: Number(fields.get('agricultural_product_id')),
        quantity: fields.get('quantity'),
        unit: fields.get('unit'),
        origin_address: fields.get('origin_address'),
        origin_city: fields.get('origin_city'),
        origin_state: fields.get('origin_state'),
        origin_country: 'BR',
        harvest_date: fields.get('harvest_date'),
        available_until: new Date(fields.get('available_until')).toISOString(),
        asking_price: fields.get('asking_price'),
        donation_eligible: form.querySelector('[name="donation_eligible"]').checked,
        accepted_logistics_modes: modes,
    };
    if (fields.get('quality_grade_id')) payload.quality_grade_id = Number(fields.get('quality_grade_id'));
    if (fields.get('minimum_price')) payload.minimum_price = fields.get('minimum_price');

    button.disabled = true;
    try {
        const lot = await api('/surplus', { method: 'POST', body: JSON.stringify(payload) });
        toast('Excedente publicado. Lote #' + lot.id + ' está no catálogo.');
        state.catalogFilters.page = 1;
        location.hash = '#/catalogo';
    } catch (error) {
        message.textContent = error.message;
    } finally {
        button.disabled = false;
    }
}
