import { paintNavigation } from './navigation.js';
import { api } from './api.js';
import { short } from './format.js';
import { roleLabels } from './labels.js';
import { state } from './state.js';
import { toast } from './ui.js';
import { selectedTradeId } from '../pages/tracking.js';
import { route } from '../router.js';

export function setSession(user, token, expiresAt) {
    state.user = user;
    state.token = token;
    state.tokenExpiresAt = expiresAt || null;
    sessionStorage.setItem('foodrescue_token', token);
    sessionStorage.setItem('foodrescue_user', JSON.stringify(user));
    if (expiresAt) sessionStorage.setItem('foodrescue_token_expires_at', expiresAt);
    updateSessionUi();
}

export function clearSession() {
    state.user = null;
    state.token = null;
    state.tokenExpiresAt = null;
    state.trades = [];
    state.selectedTradeId = null;
    state.referenceCatalog = null;
    ['foodrescue_token', 'foodrescue_user', 'foodrescue_token_expires_at']
        .forEach(function (key) { sessionStorage.removeItem(key); });
    updateSessionUi();
}

export function sessionIsExpired() {
    return Boolean(state.tokenExpiresAt) && new Date(state.tokenExpiresAt).getTime() <= Date.now();
}

export function currentRole() {
    return (state.user?.roles || []).find(function (role) { return roleLabels[role]; }) || null;
}

export async function bootSession() {
    if (!state.token) return false;
    const previousRole = currentRole();
    if (sessionIsExpired()) {
        clearSession();
        return true;
    }
    try {
        const user = await api('/auth/me', { silent: true });
        // Um 401 em outra chamada pode ter encerrado a sessão enquanto isto ia e
        // voltava; nesse caso a resposta é descartada em vez de ressuscitar o usuário.
        if (!state.token) return true;
        state.user = user;
        sessionStorage.setItem('foodrescue_user', JSON.stringify(state.user));
    } catch (_) {
        // Um 401 já encerrou a sessão; falhas de rede mantêm o usuário em cache.
    }
    updateSessionUi();

    return previousRole !== currentRole();
}

export async function handleLogout() {
    try {
        await api('/auth/logout', { method: 'POST' });
    } catch (_) {
        // O token local é descartado mesmo que a revogação remota falhe.
    }
    clearSession();
    toast('Sessão encerrada.');
    const previousHash = location.hash;
    location.hash = '#/';
    if (previousHash === '' || previousHash === '#/') route();
}

export function updateSessionUi() {
    document.querySelectorAll('[data-open-auth]').forEach(function (button) {
        button.textContent = state.user ? short(state.user.solana_wallet_address || state.wallet || state.user.name, 6, 4) : 'Conectar carteira';
    });
    document.querySelectorAll('[data-logout]').forEach(function (button) { button.hidden = !state.user; });
    paintNavigation();
}
