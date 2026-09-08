export function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, function (char) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char];
    });
}

export function short(value, start = 5, end = 4) {
    if (!value || value.startsWith('Configure')) return value || 'Não configurado';
    return value.length > start + end + 3 ? value.slice(0, start) + '…' + value.slice(-end) : value;
}

export function money(value) {
    return new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value || 0));
}

export function quantityLabel(quantities) {
    const entries = Object.entries(quantities || {});
    if (!entries.length) return '—';

    return entries.map(function (entry) {
        return new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 3 }).format(Number(entry[1])) + ' ' + entry[0];
    }).join(' · ');
}

export function reputationLabel(ratings) {
    if (!ratings || !ratings.count) return ['Sem avaliações', 'reputação ainda não formada'];

    return [
        new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(ratings.average) + ' / 5',
        ratings.count + (ratings.count === 1 ? ' avaliação' : ' avaliações'),
    ];
}

export function formatDateTime(value) {
    if (!value) return 'Não definido';

    return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value));
}

export function localDateTimeValue(date) {
    const offset = date.getTimezoneOffset() * 60000;

    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

export function deadlineLabel(availableUntil) {
    const remaining = availableUntil ? new Date(availableUntil).getTime() - Date.now() : NaN;
    if (Number.isNaN(remaining)) return 'Prazo não informado';
    if (remaining <= 0) return 'Prazo encerrado';
    const hours = Math.round(remaining / 3600000);
    return hours >= 48 ? Math.round(hours / 24) + ' dias restantes' : Math.max(1, hours) + 'h restantes';
}

export function starBar(average) {
    const rounded = Math.round(Number(average) || 0);

    return '<span class="stars" aria-hidden="true">' + '★'.repeat(rounded) + '☆'.repeat(Math.max(0, 5 - rounded)) + '</span>';
}

export function uint8ToBase64(bytes) {
    let binary = '';
    bytes.forEach(function (byte) { binary += String.fromCharCode(byte); });
    return btoa(binary);
}

export function numberChanged(value, current) {
    if (value === '' || value === null) return false;
    if (current === null || current === undefined) return true;

    return Number(value) !== Number(current);
}
