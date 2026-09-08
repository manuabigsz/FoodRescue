export const brazilStates = [
    ['AC', 'Acre'], ['AL', 'Alagoas'], ['AP', 'Amapá'], ['AM', 'Amazonas'],
    ['BA', 'Bahia'], ['CE', 'Ceará'], ['DF', 'Distrito Federal'], ['ES', 'Espírito Santo'],
    ['GO', 'Goiás'], ['MA', 'Maranhão'], ['MT', 'Mato Grosso'], ['MS', 'Mato Grosso do Sul'],
    ['MG', 'Minas Gerais'], ['PA', 'Pará'], ['PB', 'Paraíba'], ['PR', 'Paraná'],
    ['PE', 'Pernambuco'], ['PI', 'Piauí'], ['RJ', 'Rio de Janeiro'], ['RN', 'Rio Grande do Norte'],
    ['RS', 'Rio Grande do Sul'], ['RO', 'Rondônia'], ['RR', 'Roraima'], ['SC', 'Santa Catarina'],
    ['SP', 'São Paulo'], ['SE', 'Sergipe'], ['TO', 'Tocantins'],
];

export function stateOptions(selected = '') {
    return '<option value="">Selecione o estado</option>' + brazilStates.map(function ([uf, name]) {
        return '<option value="' + uf + '"' + (selected === uf ? ' selected' : '') + '>' + name + ' (' + uf + ')</option>';
    }).join('');
}

export function formatPhone(value) {
    let digits = String(value || '').replace(/\D/g, '');
    if (digits.startsWith('55') && digits.length > 11) digits = digits.slice(2);
    digits = digits.slice(0, 11);
    if (digits.length <= 2) return digits ? '(' + digits : '';
    if (digits.length <= 6) return '(' + digits.slice(0, 2) + ') ' + digits.slice(2);
    if (digits.length <= 10) return '(' + digits.slice(0, 2) + ') ' + digits.slice(2, 6) + '-' + digits.slice(6);

    return '(' + digits.slice(0, 2) + ') ' + digits.slice(2, 7) + '-' + digits.slice(7);
}

export function bindPhoneMasks(root = document) {
    root.querySelectorAll('[data-phone-mask]').forEach(function (input) {
        input.type = 'tel';
        input.inputMode = 'numeric';
        input.maxLength = 15;
        input.placeholder = '(11) 99999-9999';
        input.value = formatPhone(input.value);
        input.addEventListener('input', function () {
            input.value = formatPhone(input.value);
        });
    });
}

export function formatUsd(value) {
    const raw = String(value || '').replace(/[^0-9.]/g, '');
    const separator = raw.indexOf('.');
    const integer = (separator >= 0 ? raw.slice(0, separator) : raw).replace(/^0+(?=\d)/, '') || '0';
    const cents = separator >= 0 ? raw.slice(separator + 1).slice(0, 6) : '';
    const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return 'US$ ' + grouped + (separator >= 0 ? '.' + cents : '');
}

export function normalizeUsd(value) {
    const normalized = String(value || '').replace(/[^0-9.]/g, '');

    return normalized;
}

export function bindUsdMasks(root = document) {
    root.querySelectorAll('[data-usd-mask]').forEach(function (input) {
        input.type = 'text';
        input.inputMode = 'decimal';
        input.maxLength = 24;
        input.placeholder = 'US$ 0.00';
        input.value = input.value.trim() ? formatUsd(input.value) : '';
        input.addEventListener('input', function () {
            input.value = input.value.trim() ? formatUsd(input.value) : '';
        });
    });
}
