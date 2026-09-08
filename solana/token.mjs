/**
 * Imprime um token da API para usar nos scripts, evitando copiar do navegador.
 *
 *   node token.mjs comprador@foodrescue.test 'FoodRescue#Local2026'
 *   npm run token -- comprador@foodrescue.test 'FoodRescue#Local2026'
 *
 * Com --export, imprime a linha pronta do PowerShell:
 *   npm run token -- comprador@foodrescue.test 'senha' --export
 */
const [email, password, ...flags] = process.argv.slice(2);
const api = process.env.API_BASE_URL || 'http://localhost:8080/api/v1';

if (!email || !password) {
    console.error('Uso: node token.mjs <email> <senha> [--export] [--var NOME]');
    process.exit(1);
}

const response = await fetch(`${api}/auth/login`, {
    method: 'POST',
    headers: { 'content-type': 'application/json', accept: 'application/json' },
    body: JSON.stringify({ email, password, device_name: 'solana-scripts' }),
});

const body = await response.json().catch(() => ({}));

if (!response.ok || !body.data?.token) {
    console.error(`Login falhou (${response.status}): ${body.message || JSON.stringify(body)}`);
    console.error(`API usada: ${api} — ajuste API_BASE_URL se o servidor estiver em outra porta.`);
    process.exit(1);
}

const varIndex = flags.indexOf('--var');
const variavel = varIndex >= 0 ? flags[varIndex + 1] : 'API_TOKEN';

if (flags.includes('--export')) {
    console.log(`$env:${variavel}="${body.data.token}"`);
} else {
    console.log(body.data.token);
}
