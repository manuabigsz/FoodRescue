import { openAuthOrDashboard } from '../auth/modal.js';
import { config } from './config.js';
import { esc, short } from './format.js';

export const main = document.querySelector('#main-content');

export const modal = document.querySelector('[data-auth-modal]');

export const toastRegion = document.querySelector('.toast-region');

export function toast(message, type = '') {
    const node = document.createElement('div');
    node.className = 'toast ' + type;
    node.textContent = message;
    toastRegion.appendChild(node);
    setTimeout(function () { node.remove(); }, 4200);
}

export function setPage(html, title) {
    main.innerHTML = html;
    document.title = title + ' — FoodRescue';
    window.scrollTo({ top: 0, behavior: 'instant' });
    bindPageActions();
}

export function panelShell(title, body) {
    return '<article class="panel action-panel"><h3>' + esc(title) + '</h3>' + body + '<p class="form-message" data-panel-message></p></article>';
}

export function detailCell(label, value) {
    return '<div class="detail"><small>' + esc(label) + '</small><strong>' + esc(value) + '</strong></div>';
}

export function selectOptions(options, selected) {
    return options.map(function (option) {
        return '<option value="' + option[0] + '"' + (String(selected) === option[0] ? ' selected' : '') + '>' + option[1] + '</option>';
    }).join('');
}

export function howCard(step, title, text) {
    return '<article class="how-card"><span class="step">' + step + '</span><h3>' + title + '</h3><p>' + text + '</p></article>';
}

export function roleCard(icon, title, text) {
    return '<article class="role-card"><span class="role-icon">' + icon + '</span><h3>' + title + '</h3><p>' + text + '</p></article>';
}

export function chainCard(eyebrow, title, description, address, featured) {
    return '<article class="chain-card' + (featured ? ' featured' : '') + '"><span class="eyebrow' + (featured ? ' eyebrow-light' : '') + '">' + eyebrow + '</span><h2>' + title + '</h2><p>' + description + '</p>' +
        '<div class="address"><span title="' + esc(address) + '">' + esc(short(address, 10, 8)) + '</span><a href="' + solscanAddress(address) + '" target="_blank" rel="noopener">Solscan ↗</a></div></article>';
}

export function solscanAddress(address) {
    if (!address || address.startsWith('Configure')) return config.explorer + '/?cluster=' + encodeURIComponent(config.network);

    return config.explorer + '/account/' + encodeURIComponent(address) + '?cluster=' + encodeURIComponent(config.network);
}

export function bindPageActions() {
    main.querySelectorAll('[data-open-auth]').forEach(function (button) { button.addEventListener('click', function () { openAuthOrDashboard('register'); }); });
    document.querySelector('[data-new-lot]')?.addEventListener('click', function () {
        location.hash = '#/publicar';
    });
}
