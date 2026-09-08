import { config } from './config.js';
import { clearSession } from './session.js';
import { state } from './state.js';
import { toast } from './ui.js';

export function buildQuery(params) {
    const search = new URLSearchParams();
    Object.keys(params || {}).forEach(function (key) {
        const value = params[key];
        if (value === null || value === undefined || value === '') return;
        search.append(key, String(value));
    });
    const query = search.toString();
    return query ? '?' + query : '';
}

export async function api(path, options = {}) {
    const { query, raw = false, silent = false, ...init } = options;
    const headers = { Accept: 'application/json', 'Content-Type': 'application/json', ...(init.headers || {}) };
    if (state.token) headers.Authorization = 'Bearer ' + state.token;
    const response = await fetch(config.apiUrl + path + buildQuery(query), { ...init, headers });
    const payload = response.status === 204 ? null : await response.json().catch(function () { return null; });
    if (response.status === 401 && state.token) {
        clearSession();
        if (!silent) toast('Sua sessão expirou. Entre novamente para continuar.', 'error');
    }
    if (response.status === 429) {
        const retryAfter = Number(response.headers.get('Retry-After'));
        throw new Error('Muitas ações em sequência. Tente de novo em ' + (retryAfter > 0 ? retryAfter + ' segundos' : 'alguns instantes') + '.');
    }
    if (!response.ok) {
        const validation = payload && payload.errors ? Object.values(payload.errors).flat()[0] : null;
        throw new Error(validation || (payload && payload.message) || 'Não foi possível concluir a solicitação.');
    }
    if (raw) return payload;
    return payload && Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;
}
