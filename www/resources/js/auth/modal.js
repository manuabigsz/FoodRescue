import { api } from '../core/api.js';
import { esc, short, uint8ToBase64 } from '../core/format.js';
import { setSession } from '../core/session.js';
import { state } from '../core/state.js';
import { modal, toast } from '../core/ui.js';

export function openAuthOrDashboard(tab = 'login') {
    if (!state.user) {
        openAuth(tab);
        return;
    }
    location.hash = '#/dashboard';
}

export function openAuth(tab = 'login') {
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    renderAuthPanels();
    selectAuthTab(tab);
    setTimeout(function () { modal.querySelector('button, input')?.focus(); }, 0);
}

export function closeAuth() {
    modal.hidden = true;
    document.body.style.overflow = '';
}

export function selectAuthTab(tab) {
    document.querySelectorAll('[data-auth-tab]').forEach(function (button) {
        const active = button.dataset.authTab === tab;
        button.classList.toggle('active', active);
        button.setAttribute('aria-selected', String(active));
    });
    document.querySelectorAll('[data-auth-panel]').forEach(function (panel) { panel.hidden = panel.dataset.authPanel !== tab; });
}

export function walletCard() {
    const address = state.wallet ? short(state.wallet, 7, 6) : 'Phantom ou carteira compatível';
    return '<div class="wallet-connect-card"><strong>' + (state.wallet ? 'Carteira conectada: ' + esc(address) : 'Conecte sua carteira Solana') + '</strong><p>Compartilhamos somente o endereço público. Para o cadastro, você assinará uma mensagem de comprovação.</p><button class="button button-ghost button-full" type="button" data-connect-wallet>' + (state.wallet ? 'Trocar carteira' : 'Conectar carteira') + '</button></div>';
}

export function renderAuthPanels() {
    const login = document.querySelector('[data-auth-panel="login"]');
    const register = document.querySelector('[data-auth-panel="register"]');
    login.innerHTML = walletCard() + '<div class="form-divider">acesso à conta</div><form class="form-grid" data-login-form><div class="field"><label for="login-email">E-mail</label><input id="login-email" name="email" type="email" autocomplete="email" required></div><div class="field"><label for="login-password">Senha</label><input id="login-password" name="password" type="password" autocomplete="current-password" required></div><button class="button button-full" type="submit">Entrar no FoodRescue</button><p class="form-message" data-form-message></p></form>';
    register.innerHTML = walletCard() + '<form class="form-grid" data-register-form><div class="field-row"><div class="field"><label for="reg-name">Nome</label><input id="reg-name" name="name" required maxlength="120"></div><div class="field"><label for="reg-email">E-mail</label><input id="reg-email" name="email" type="email" required></div></div><div class="field"><label for="reg-role">Como você participa?</label><select id="reg-role" name="role"><option value="producer">Produtor</option><option value="buyer">Comprador</option><option value="carrier">Transportadora</option><option value="ngo">ONG / Instituição social</option></select></div><div data-profile-fields></div><div class="field-row"><div class="field"><label for="reg-password">Senha</label><input id="reg-password" name="password" type="password" autocomplete="new-password" required minlength="12"></div><div class="field"><label for="reg-password-confirmation">Confirmar senha</label><input id="reg-password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div></div><button class="button button-full" type="submit">Assinar e criar conta</button><p class="form-message" data-form-message></p></form>';
    bindAuthForms();
    paintProfileFields('producer');
}

export function paintProfileFields(role) {
    const target = document.querySelector('[data-profile-fields]');
    if (!target) return;
    let specific = '';
    if (role === 'producer') specific = '<div class="field-row"><div class="field"><label>Tipo de produtor</label><select name="producer_type"><option value="individual">Pessoa física</option><option value="company">Empresa</option><option value="cooperative">Cooperativa</option></select></div><div class="field"><label>Nome da propriedade</label><input name="farm_name"></div></div><div class="field"><label>Organização (empresa ou cooperativa)</label><input name="organization_name"></div>';
    if (role === 'buyer') specific = '<div class="field-row"><div class="field"><label>Tipo de comprador</label><select name="buyer_type"><option value="individual">Pessoa física</option><option value="company">Empresa</option></select></div><div class="field"><label>Organização (se empresa)</label><input name="organization_name"></div></div>';
    if (role === 'carrier') specific = '<div class="field-row"><div class="field"><label>Empresa</label><input name="company_name" required></div><div class="field"><label>Responsável</label><input name="contact_name" required></div></div><div class="field"><label>Regiões atendidas</label><input name="service_regions" placeholder="São Paulo, Campinas" required></div>';
    if (role === 'ngo') specific = '<div class="field-row"><div class="field"><label>Organização</label><input name="organization_name" required></div><div class="field"><label>Número de registro</label><input name="registration_number" required></div></div><div class="field"><label>Responsável</label><input name="contact_name" required></div>';
    target.innerHTML = specific + '<div class="field-row"><div class="field"><label>Telefone</label><input name="phone" required></div><div class="field"><label>Documento</label><input name="document_number"' + (role === 'ngo' ? '' : ' required') + '></div></div><div class="field-row"><div class="field"><label>Cidade</label><input name="city" required></div><div class="field"><label>Estado</label><input name="state" required maxlength="100"></div></div><div class="field"><label>Endereço</label><input name="address_line" required></div>';
}

export function bindAuthForms() {
    document.querySelectorAll('[data-connect-wallet]').forEach(function (button) { button.addEventListener('click', connectWallet); });
    document.querySelector('#reg-role')?.addEventListener('change', function (event) { paintProfileFields(event.target.value); });
    document.querySelector('[data-login-form]')?.addEventListener('submit', handleLogin);
    document.querySelector('[data-register-form]')?.addEventListener('submit', handleRegister);
}

export async function connectWallet() {
    const provider = window.solana;
    if (!provider?.connect) {
        toast('Nenhuma carteira Solana compatível foi encontrada no navegador.', 'error');
        return;
    }
    try {
        const result = await provider.connect();
        state.wallet = result.publicKey.toString();
        renderAuthPanels();
        toast('Carteira conectada com segurança.');
    } catch (error) {
        toast(error.message || 'Conexão cancelada.', 'error');
    }
}

export async function handleLogin(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const message = form.querySelector('[data-form-message]');
    const button = form.querySelector('[type="submit"]');
    message.textContent = '';
    button.disabled = true;
    try {
        const fields = new FormData(form);
        const result = await api('/auth/login', { method: 'POST', body: JSON.stringify({ email: fields.get('email'), password: fields.get('password'), device_name: 'foodrescue-web' }) });
        setSession(result.user, result.token, result.expires_at);
        closeAuth();
        toast('Bem-vindo ao FoodRescue.');
        location.hash = '#/dashboard';
    } catch (error) {
        message.textContent = error.message;
    } finally {
        button.disabled = false;
    }
}

export async function handleRegister(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const message = form.querySelector('[data-form-message]');
    const button = form.querySelector('[type="submit"]');
    message.textContent = '';
    if (!state.wallet) {
        message.textContent = 'Conecte sua carteira antes de criar a conta.';
        return;
    }
    button.disabled = true;
    try {
        const challenge = await api('/auth/wallet/challenge', { method: 'POST', body: JSON.stringify({ wallet_address: state.wallet }) });
        const encoded = new TextEncoder().encode(challenge.message);
        const signed = await window.solana.signMessage(encoded, 'utf8');
        const fields = new FormData(form);
        const role = fields.get('role');
        const profile = { phone: fields.get('phone'), country: 'Brasil', state: fields.get('state'), city: fields.get('city'), address_line: fields.get('address_line'), postal_code: null };
        if (role !== 'ngo') profile.document_number = fields.get('document_number');
        if (role === 'producer') { profile.producer_type = fields.get('producer_type'); profile.farm_name = fields.get('farm_name') || null; if (fields.get('organization_name')) profile.organization_name = fields.get('organization_name'); }
        if (role === 'buyer') { profile.buyer_type = fields.get('buyer_type'); if (fields.get('organization_name')) profile.organization_name = fields.get('organization_name'); }
        if (role === 'carrier') { profile.company_name = fields.get('company_name'); profile.contact_name = fields.get('contact_name'); profile.service_regions = String(fields.get('service_regions')).split(',').map(function (item) { return item.trim(); }).filter(Boolean); profile.vehicle_types = []; profile.max_capacity_kg = null; }
        if (role === 'ngo') { profile.organization_name = fields.get('organization_name'); profile.registration_number = fields.get('registration_number'); profile.contact_name = fields.get('contact_name'); profile.description = null; }
        const payload = { name: fields.get('name'), email: fields.get('email'), password: fields.get('password'), password_confirmation: fields.get('password_confirmation'), role: role, profile: profile, solana_wallet_address: state.wallet, wallet_challenge_id: challenge.id, wallet_signature: uint8ToBase64(signed.signature) };
        await api('/auth/register', { method: 'POST', body: JSON.stringify(payload) });
        toast('Conta criada e carteira verificada. Faça seu primeiro acesso.');
        selectAuthTab('login');
    } catch (error) {
        message.textContent = error.message;
    } finally {
        button.disabled = false;
    }
}
