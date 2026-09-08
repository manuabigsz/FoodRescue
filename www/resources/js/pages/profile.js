import { openAuthOrDashboard } from '../auth/modal.js';
import { api } from '../core/api.js';
import { esc, formatDateTime, short, uint8ToBase64 } from '../core/format.js';
import { clearSession, updateSessionUi } from '../core/session.js';
import { state } from '../core/state.js';
import { setPage, toast } from '../core/ui.js';

export async function renderProfile() {
    setPage(
        '<div class="page"><div class="shell"><div class="page-head"><div><span class="eyebrow">CONTA</span><h1>Perfil</h1><p>Dados de acesso e carteira Solana vinculada.</p></div></div>' +
        '<div data-profile><div class="skeleton" style="min-height:200px"></div></div></div></div>',
        'Perfil',
    );
    const target = document.querySelector('[data-profile]');
    if (!target) return;

    if (!state.token) {
        target.innerHTML = '<div class="empty-state"><h2>Entre para ver seu perfil</h2><button class="button" type="button" data-open-auth>Entrar na conta</button></div>';
        target.querySelector('[data-open-auth]').addEventListener('click', function () { openAuthOrDashboard('login'); });

        return;
    }

    let user;
    try {
        user = await api('/auth/me');
        state.user = user;
        sessionStorage.setItem('foodrescue_user', JSON.stringify(user));
    } catch (error) {
        target.innerHTML = '<div class="empty-state"><h2>Não foi possível carregar o perfil</h2><p>' + esc(error.message) + '</p></div>';

        return;
    }

    target.innerHTML =
        '<div class="panel-grid">' +
        '<article class="panel"><h2>Dados da conta</h2>' +
        '<form class="form-grid" data-account-form><div class="field"><label for="profile-name">Nome</label><input id="profile-name" name="name" value="' + esc(user.name) + '" maxlength="120" required></div>' +
        '<div class="field"><label for="profile-email">E-mail</label><input id="profile-email" name="email" type="email" value="' + esc(user.email) + '" required></div>' +
        '<div class="field"><label for="profile-current">Senha atual (exigida para trocar o e-mail)</label><input id="profile-current" name="current_password" type="password" autocomplete="current-password"></div>' +
        '<button class="button button-small" type="submit">Salvar dados</button><p class="form-message" data-form-message></p></form></article>' +
        '<article class="panel"><h2>Senha</h2>' +
        '<form class="form-grid" data-password-form><div class="field"><label for="pwd-current">Senha atual</label><input id="pwd-current" name="current_password" type="password" autocomplete="current-password" required></div>' +
        '<div class="field"><label for="pwd-new">Nova senha (mínimo 6 caracteres)</label><input id="pwd-new" name="password" type="password" autocomplete="new-password" minlength="6" required></div>' +
        '<div class="field"><label for="pwd-confirm">Confirmar nova senha</label><input id="pwd-confirm" name="password_confirmation" type="password" autocomplete="new-password" minlength="6" required></div>' +
        '<button class="button button-small" type="submit">Trocar senha</button><p class="form-message" data-form-message></p></form></article></div>' +
        '<article class="panel" style="margin-top:1rem"><h2>Carteira Solana</h2>' +
        '<p>' + (user.solana_wallet_address
            ? 'Vinculada: <strong title="' + esc(user.solana_wallet_address) + '">' + esc(short(user.solana_wallet_address, 8, 6)) + '</strong> · ' + (user.solana_wallet_verified ? 'verificada em ' + esc(formatDateTime(user.solana_wallet_verified_at)) : 'ainda não verificada')
            : 'Nenhuma carteira vinculada a esta conta.') + '</p>' +
        '<p class="footer-note">A troca exige assinar uma mensagem de comprovação. Nunca pedimos sua chave privada.</p>' +
        '<div class="action-bar"><button class="button button-small button-ghost" type="button" data-change-wallet>' + (user.solana_wallet_address ? 'Trocar carteira' : 'Vincular carteira') + '</button></div>' +
        '<p class="form-message" data-wallet-message></p></article>' +
        '<article class="panel" style="margin-top:1rem"><h2>Sessões</h2>' +
        '<p>Encerrar todas as sessões revoga os tokens de todos os dispositivos, inclusive este. Use se suspeitar que alguém teve acesso à sua conta.</p>' +
        '<div class="action-bar"><button class="button button-small button-ghost" type="button" data-logout-all>Sair de todas as sessões</button></div>' +
        '<p class="form-message" data-sessions-message></p></article>';

    target.querySelector('[data-account-form]').addEventListener('submit', async function (event) {
        event.preventDefault();
        const form = event.currentTarget;
        const message = form.querySelector('[data-form-message]');
        const fields = new FormData(form);
        const payload = {};
        message.textContent = '';
        if (fields.get('name') !== user.name) payload.name = fields.get('name');
        if (fields.get('email') !== user.email) {
            payload.email = fields.get('email');
            payload.current_password = fields.get('current_password');
        }
        if (!Object.keys(payload).length) {
            message.textContent = 'Nada foi alterado.';

            return;
        }
        try {
            const updated = await api('/auth/me', { method: 'PATCH', body: JSON.stringify(payload) });
            state.user = updated;
            sessionStorage.setItem('foodrescue_user', JSON.stringify(updated));
            updateSessionUi();
            toast(payload.email ? 'Dados salvos. A troca de e-mail encerra as outras sessões.' : 'Dados salvos.');
            renderProfile();
        } catch (error) {
            message.textContent = error.message;
        }
    });

    target.querySelector('[data-password-form]').addEventListener('submit', async function (event) {
        event.preventDefault();
        const form = event.currentTarget;
        const message = form.querySelector('[data-form-message]');
        const fields = new FormData(form);
        message.textContent = '';
        try {
            await api('/auth/password', {
                method: 'PUT',
                body: JSON.stringify({
                    current_password: fields.get('current_password'),
                    password: fields.get('password'),
                    password_confirmation: fields.get('password_confirmation'),
                }),
            });
            toast('Senha alterada. Entre novamente.');
            clearSession();
            location.hash = '#/';
        } catch (error) {
            message.textContent = error.message;
        }
    });

    target.querySelector('[data-logout-all]').addEventListener('click', async function (event) {
        const message = target.querySelector('[data-sessions-message]');
        message.textContent = '';
        event.currentTarget.disabled = true;
        try {
            await api('/auth/logout-all', { method: 'POST' });
            clearSession();
            toast('Todas as sessões foram encerradas.');
            location.hash = '#/';
        } catch (error) {
            message.textContent = error.message;
            event.currentTarget.disabled = false;
        }
    });

    target.querySelector('[data-change-wallet]').addEventListener('click', async function (event) {
        const message = target.querySelector('[data-wallet-message]');
        message.textContent = '';
        event.currentTarget.disabled = true;
        try {
            if (!window.solana?.connect) throw new Error('Nenhuma carteira Solana compatível foi encontrada no navegador.');
            const connection = await window.solana.connect();
            const address = connection.publicKey.toString();
            const challenge = await api('/auth/wallet/change/challenge', { method: 'POST', body: JSON.stringify({ wallet_address: address }) });
            const signed = await window.solana.signMessage(new TextEncoder().encode(challenge.message), 'utf8');
            await api('/auth/wallet/change/verify', {
                method: 'POST',
                body: JSON.stringify({
                    challenge_id: challenge.id,
                    signature: uint8ToBase64(signed.signature),
                }),
            });
            toast('Carteira verificada.');
            renderProfile();
        } catch (error) {
            message.textContent = error.message;
            event.currentTarget.disabled = false;
        }
    });
}
