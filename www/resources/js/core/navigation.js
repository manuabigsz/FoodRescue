import { esc } from './format.js';
import { currentRole } from './session.js';
import { state } from './state.js';

/**
 * Menu de cada perfil, em ordem de uso: primeiro o que o ator faz no dia a dia,
 * depois o que ele consulta. Uma tela só aparece para quem pode usá-la — a API
 * recusaria as demais de qualquer forma.
 *
 * `nav` casa com o caminho do hash para marcar o item ativo.
 */
const MENUS = {
    visitante: [
        { nav: 'catalogo', hash: '#/catalogo', label: 'Excedentes' },
        { nav: 'doacoes', hash: '#/doacoes', label: 'Doações' },
        { nav: 'rede', hash: '#/rede', label: 'Solana' },
    ],
    producer: [
        { nav: 'meus-lotes', hash: '#/meus-lotes', label: 'Meus lotes' },
        { nav: 'publicar', hash: '#/publicar', label: 'Publicar' },
        { nav: 'acompanhamento', hash: '#/acompanhamento', label: 'Minhas vendas' },
        { nav: 'dashboard', hash: '#/dashboard', label: 'Painel' },
    ],
    buyer: [
        { nav: 'catalogo', hash: '#/catalogo', label: 'Excedentes' },
        { nav: 'acompanhamento', hash: '#/acompanhamento', label: 'Minhas compras' },
        { nav: 'dashboard', hash: '#/dashboard', label: 'Painel' },
    ],
    carrier: [
        { nav: 'fretes', hash: '#/fretes', label: 'Rotas abertas' },
        { nav: 'acompanhamento', hash: '#/acompanhamento', label: 'Meus fretes' },
        { nav: 'dashboard', hash: '#/dashboard', label: 'Painel' },
    ],
    ngo: [
        { nav: 'catalogo', hash: '#/catalogo', label: 'Lotes para doação' },
        { nav: 'acompanhamento', hash: '#/acompanhamento', label: 'Minhas doações' },
        { nav: 'doacoes', hash: '#/doacoes', label: 'Proof of Rescue' },
        { nav: 'dashboard', hash: '#/dashboard', label: 'Painel' },
    ],
    admin: [
        { nav: 'admin', hash: '#/admin?tab=visao', label: 'Visão geral' },
        { nav: 'admin-usuarios', hash: '#/admin?tab=usuarios', label: 'Usuários' },
        { nav: 'admin-catalogo', hash: '#/admin?tab=catalogo', label: 'Catálogo' },
        { nav: 'admin-ajustes', hash: '#/admin?tab=ajustes', label: 'Prazos' },
        { nav: 'admin-solana', hash: '#/admin?tab=solana', label: 'Solana' },
    ],
};

/** Itens de conta, comuns a quem está autenticado e fora do menu principal. */
const CONTA = [
    { nav: 'perfil', hash: '#/perfil', label: 'Perfil' },
    { nav: 'reputacao', hash: '#/reputacao', label: 'Reputação' },
];

/** O administrador não opera o marketplace; os demais não veem a rede como item primário. */
export function menuFor(role) {
    return MENUS[role] || MENUS.visitante;
}

export function accountMenuFor(role) {
    if (!role) return [];

    return role === 'admin' ? CONTA : [...CONTA, { nav: 'rede', hash: '#/rede', label: 'Rede Solana' }];
}

/** Papel efetivo: o admin tem menu próprio mesmo não sendo um ator do marketplace. */
export function navigationRole() {
    if (!state.user) return null;

    return state.user.roles?.includes('admin') ? 'admin' : currentRole();
}

function itemsHtml(items, active) {
    return items.map(function (item) {
        return '<a href="' + item.hash + '" data-nav="' + item.nav + '"' + (item.nav === active ? ' class="active" aria-current="page"' : '') + '>' + esc(item.label) + '</a>';
    }).join('');
}

/** Caminho atual em formato de item de menu, incluindo a aba do admin. */
export function activeNav() {
    const [path, query] = location.hash.replace(/^#\/?/, '').split('?');
    if (path === 'admin') {
        const tab = new URLSearchParams(query || '').get('tab') || 'visao';

        return tab === 'visao' ? 'admin' : 'admin-' + tab;
    }

    return path || 'inicio';
}

export function paintNavigation() {
    const role = navigationRole();
    const active = activeNav();
    const principal = menuFor(role);
    const conta = accountMenuFor(role);

    const desktop = document.querySelector('[data-nav-desktop]');
    if (desktop) desktop.innerHTML = itemsHtml(principal, active);

    const mobile = document.querySelector('[data-nav-mobile]');
    if (mobile) {
        mobile.innerHTML = itemsHtml(principal, active)
            + (conta.length ? '<span class="mobile-nav-divider">Conta</span>' + itemsHtml(conta, active) : '');
    }
}
