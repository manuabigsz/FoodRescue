import { api } from '../core/api.js';
import { config } from '../core/config.js';
import { esc, money, quantityLabel } from '../core/format.js';
import { roleLabels, settingLabels, userStatusLabels } from '../core/labels.js';
import { connectedWallet, protocolConfigAddress, signAndSend, walletAvailable, walletErrorMessage } from '../core/solana.js';
import { state } from '../core/state.js';
import { detailCell, setPage, toast } from '../core/ui.js';
import { paintStatusList } from './dashboard.js';

export const adminTabs = [
    ['visao', 'Visão geral'],
    ['usuarios', 'Usuários'],
    ['catalogo', 'Catálogo'],
    ['ajustes', 'Prazos'],
    ['solana', 'Solana'],
];

export async function renderAdmin() {
    const tab = new URLSearchParams(location.hash.split('?')[1] || '').get('tab') || 'visao';
    setPage(
        '<div class="page"><div class="shell"><div class="page-head"><div><span class="eyebrow">ADMINISTRAÇÃO</span><h1>Painel do protocolo</h1><p>Usuários, catálogo de referência, prazos operacionais e métricas de impacto.</p></div></div>' +
        '<div class="role-switcher">' + adminTabs.map(function (item) {
            return '<a class="' + (item[0] === tab ? 'active' : '') + '" href="#/admin?tab=' + item[0] + '">' + item[1] + '</a>';
        }).join('') + '</div>' +
        '<div data-admin><div class="skeleton" style="min-height:220px"></div></div></div></div>',
        'Administração',
    );
    const target = document.querySelector('[data-admin]');
    if (!target) return;

    if (!state.user?.roles?.includes('admin')) {
        target.innerHTML = '<div class="empty-state"><h2>Acesso restrito</h2><p>' + (state.token ? 'Esta área é exclusiva de administradores.' : 'Entre com uma conta de administrador.') + '</p></div>';

        return;
    }

    const painters = { visao: paintAdminOverview, usuarios: paintAdminUsers, catalogo: paintAdminCatalog, ajustes: paintAdminSettings, solana: paintAdminSolana };
    try {
        await (painters[tab] || paintAdminOverview)(target);
    } catch (error) {
        target.innerHTML = '<div class="empty-state"><h2>Não foi possível carregar</h2><p>' + esc(error.message) + '</p></div>';
    }
}

export async function paintAdminSolana(target) {
    let protocol = null;
    try {
        protocol = await api('/blockchain/protocol');
    } catch (_) {
        protocol = null;
    }

    if (protocol) {
        target.innerHTML = '<article class="panel"><span class="eyebrow">PROTOCOLO</span><h2>ProtocolConfig inicializado</h2>' +
            '<p>A configuração oficial já foi confirmada na Solana e será reutilizada pelos pagamentos e liquidações.</p>' +
            '<div class="details-grid">' +
            detailCell('Rede', protocol.cluster) +
            detailCell('Program ID', protocol.program_id) +
            detailCell('Mint FRUSD', protocol.mint) +
            detailCell('ProtocolConfig PDA', protocol.config_pda) +
            detailCell('Treasury', protocol.treasury_wallet) +
            detailCell('Confirmado em', protocol.confirmed_at || '—') +
            '</div></article>';

        return;
    }

    target.innerHTML = '<article class="panel"><span class="eyebrow">PROTOCOLO</span><h2>Inicializar configuração Solana</h2>' +
        '<p>Este passo é executado uma única vez por ambiente. A carteira authority cria o ProtocolConfig na Devnet e paga apenas a taxa da transação.</p>' +
        '<p class="footer-note">Conecte a carteira authority configurada no servidor. O administrador da aplicação e a authority podem ser carteiras diferentes.</p>' +
        '<button class="button button-small" type="button" data-protocol-start>Conectar authority e inicializar</button>' +
        '<p class="form-message" data-protocol-message></p></article>';

    target.querySelector('[data-protocol-start]').addEventListener('click', async function (event) {
        const button = event.currentTarget;
        const message = target.querySelector('[data-protocol-message]');
        button.disabled = true;
        message.textContent = 'Conectando a carteira authority…';
        try {
            if (!walletAvailable()) throw new Error('Nenhuma carteira Solana compatível foi encontrada no navegador.');
            const wallet = await connectedWallet();
            const configPda = await protocolConfigAddress(config.programId, wallet);
            message.textContent = 'Preparando a instrução…';
            const preparation = await api('/admin/blockchain/protocol/prepare', {
                method: 'POST',
                body: JSON.stringify({ config_pda: configPda }),
            });
            if (preparation.authority_wallet !== wallet) {
                throw new Error('A carteira conectada não é a authority configurada no servidor.');
            }
            message.textContent = 'Confirme a inicialização na carteira…';
            const signature = await signAndSend(preparation, preparation.initialize_instruction, wallet);
            message.textContent = 'Confirmando a transação no Laravel…';
            await api('/admin/blockchain/protocol/confirm', {
                method: 'POST',
                body: JSON.stringify({ signature: signature, config_pda: configPda }),
            });
            toast('ProtocolConfig inicializado e confirmado.');
            renderAdmin();
        } catch (error) {
            message.textContent = walletErrorMessage(error);
            button.disabled = false;
        }
    });
}

export async function paintAdminOverview(target) {
    const [dashboard, impact] = await Promise.all([api('/admin/dashboard'), api('/admin/impact')]);
    const users = dashboard.summary.users;

    target.innerHTML =
        '<div class="metric-grid">' + [
            ['Usuários', users.total, users.producers + ' produtores · ' + users.buyers + ' compradores'],
            ['Transportadoras e ONGs', users.carriers + ' · ' + users.ngos, 'contas ativas na rede'],
            ['Excedentes abertos', dashboard.summary.open_surplus, 'disponíveis no catálogo'],
            ['Operações comerciais', dashboard.summary.commercial_operations, dashboard.summary.donation_operations + ' doações'],
            ['Receita devolvida ao produtor', money(dashboard.summary.producer_revenue_recovered) + ' FRUSD', 'líquida da taxa'],
            ['Taxa do protocolo', money(dashboard.summary.protocol_fees) + ' FRUSD', 'acumulada'],
        ].map(function (metric) {
            return '<article class="metric-card"><small>' + esc(metric[0]) + '</small><strong>' + esc(metric[1]) + '</strong><span>' + esc(metric[2]) + '</span></article>';
        }).join('') + '</div>' +
        '<div class="panel-grid"><article class="panel"><h2>Operações por etapa</h2><div class="status-list" data-statuses></div></article>' +
        '<article class="panel"><h2>Impacto acumulado</h2><div class="details-grid">' +
            detailCell('Alimento destinado', quantityLabel(impact.quantities.total_destined)) +
            detailCell('Vendido', quantityLabel(impact.quantities.sold)) +
            detailCell('Doado', quantityLabel(impact.quantities.donated)) +
            detailCell('Frete pago', money(impact.financial.freight_paid) + ' FRUSD') +
            detailCell('Produtores atendidos', String(impact.participants.producers)) +
            detailCell('ONGs beneficiadas', String(impact.participants.ngos)) +
        '</div></article></div>';

    paintStatusList(dashboard.trade_statuses);
}

export async function paintAdminUsers(target) {
    const users = await api('/admin/users', { query: { per_page: 50 } });
    target.innerHTML = '<article class="panel"><h2>Usuários</h2><div class="quote-list">' + users.map(function (user) {
        const blocked = user.status === 'blocked';

        return '<div class="quote-row"><div><strong>' + esc(user.name) + '</strong><small>' + esc(user.email) + ' · ' + esc((user.roles || []).map(function (role) { return roleLabels[role] || role; }).join(', ') || 'sem papel') + '</small></div>' +
            '<span class="status-pill">' + esc(userStatusLabels[user.status] || user.status) + '</span>' +
            '<button class="button button-small ' + (blocked ? '' : 'button-ghost') + '" type="button" data-user-status="' + user.id + '" data-next="' + (blocked ? 'active' : 'blocked') + '">' +
            (blocked ? 'Reativar' : 'Bloquear') + '</button></div>';
    }).join('') + '</div></article>';

    target.querySelectorAll('[data-user-status]').forEach(function (button) {
        button.addEventListener('click', async function () {
            button.disabled = true;
            try {
                await api('/admin/users/' + button.dataset.userStatus + '/status', {
                    method: 'PATCH',
                    body: JSON.stringify({ status: button.dataset.next }),
                });
                toast('Status atualizado.');
                renderAdmin();
            } catch (error) {
                toast(error.message, 'error');
                button.disabled = false;
            }
        });
    });
}

export async function paintAdminCatalog(target) {
    const [products, grades] = await Promise.all([api('/admin/catalog/products'), api('/admin/catalog/quality-grades')]);

    const list = function (items, attribute) {
        return '<div class="quote-list">' + items.map(function (item) {
            return '<div class="quote-row"><div><strong>' + esc(item.name) + '</strong><small>' + (item.active ? 'Ativo' : 'Inativo') + '</small></div><span></span>' +
                '<button class="button button-small button-ghost" type="button" data-' + attribute + '="' + item.id + '" data-active="' + (item.active ? '0' : '1') + '">' +
                (item.active ? 'Desativar' : 'Ativar') + '</button></div>';
        }).join('') + '</div>';
    };

    target.innerHTML =
        '<div class="panel-grid"><article class="panel"><h2>Produtos agrícolas</h2>' + list(products, 'product-toggle') +
        '<form class="form-grid" data-new-product style="margin-top:1rem"><div class="field"><label for="new-product">Novo produto</label><input id="new-product" name="name" maxlength="120" required></div>' +
        '<button class="button button-small" type="submit">Cadastrar produto</button><p class="form-message" data-form-message></p></form></article>' +
        '<article class="panel"><h2>Classificações de qualidade</h2>' + list(grades, 'grade-toggle') +
        '<form class="form-grid" data-new-grade style="margin-top:1rem"><div class="field"><label for="new-grade">Nova classificação</label><input id="new-grade" name="name" maxlength="120" required></div>' +
        '<div class="field"><label for="new-grade-desc">Descrição (opcional)</label><input id="new-grade-desc" name="description" maxlength="255"></div>' +
        '<button class="button button-small" type="submit">Cadastrar classificação</button><p class="form-message" data-form-message></p></form></article></div>';

    const toggle = function (attribute, path) {
        target.querySelectorAll('[data-' + attribute + ']').forEach(function (button) {
            button.addEventListener('click', async function () {
                button.disabled = true;
                try {
                    await api(path + '/' + button.getAttribute('data-' + attribute), {
                        method: 'PATCH',
                        body: JSON.stringify({ active: button.dataset.active === '1' }),
                    });
                    renderAdmin();
                } catch (error) {
                    toast(error.message, 'error');
                    button.disabled = false;
                }
            });
        });
    };
    toggle('product-toggle', '/admin/catalog/products');
    toggle('grade-toggle', '/admin/catalog/quality-grades');

    const create = function (selector, path, build) {
        target.querySelector(selector).addEventListener('submit', async function (event) {
            event.preventDefault();
            const form = event.currentTarget;
            const message = form.querySelector('[data-form-message]');
            message.textContent = '';
            try {
                await api(path, { method: 'POST', body: JSON.stringify(build(new FormData(form))) });
                toast('Cadastrado.');
                renderAdmin();
            } catch (error) {
                message.textContent = error.message;
            }
        });
    };
    create('[data-new-product]', '/admin/catalog/products', function (fields) {
        return { name: fields.get('name'), active: true };
    });
    create('[data-new-grade]', '/admin/catalog/quality-grades', function (fields) {
        const payload = { name: fields.get('name'), sort_order: 0, active: true };
        if (fields.get('description')) payload.description = fields.get('description');

        return payload;
    });
}

export async function paintAdminSettings(target) {
    const settings = await api('/admin/settings/timeouts');
    const keys = Object.keys(settings);

    target.innerHTML = '<article class="panel"><h2>Prazos operacionais</h2>' +
        '<p>Valores em minutos. O prazo de cotação encerra a janela das transportadoras; o de pagamento expira a operação não financiada.</p>' +
        '<form class="form-grid" data-settings-form>' + keys.map(function (key) {
            return '<div class="field"><label for="setting-' + key + '">' + esc(settingLabels[key] || key) + '</label>' +
                '<input id="setting-' + key + '" name="' + key + '" type="number" min="1" value="' + esc(settings[key]) + '" required></div>';
        }).join('') + '<button class="button button-small" type="submit">Salvar prazos</button><p class="form-message" data-form-message></p></form></article>';

    target.querySelector('[data-settings-form]').addEventListener('submit', async function (event) {
        event.preventDefault();
        const form = event.currentTarget;
        const message = form.querySelector('[data-form-message]');
        const fields = new FormData(form);
        const payload = {};
        keys.forEach(function (key) { payload[key] = Number(fields.get(key)); });
        message.textContent = '';
        try {
            await api('/admin/settings/timeouts', { method: 'PATCH', body: JSON.stringify(payload) });
            toast('Prazos atualizados.');
        } catch (error) {
            message.textContent = error.message;
        }
    });
}
