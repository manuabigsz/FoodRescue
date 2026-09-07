const config = {
    apiUrl: (import.meta.env.VITE_API_URL || '/api/v1').replace(/\/$/, ''),
    network: import.meta.env.VITE_SOLANA_NETWORK || 'devnet',
    programId: import.meta.env.VITE_SOLANA_PROGRAM_ID || 'Configure VITE_SOLANA_PROGRAM_ID',
    frusdMint: import.meta.env.VITE_FRUSD_MINT || 'Configure VITE_FRUSD_MINT',
    explorer: import.meta.env.VITE_SOLSCAN_URL || 'https://solscan.io',
};

const state = {
    token: sessionStorage.getItem('foodrescue_token'),
    user: JSON.parse(sessionStorage.getItem('foodrescue_user') || 'null'),
    wallet: null,
    dashboardRole: 'producer',
    catalog: [],
};

const statusLabels = {
    reserved: 'Reservado',
    shipping_quotation: 'Cotando transporte',
    carrier_selected: 'Transportadora selecionada',
    buyer_managed: 'Transporte pelo comprador',
    waiting_payment: 'Aguardando pagamento',
    funded: 'Pagamento em custódia',
    ready_for_pickup: 'Pronto para coleta',
    in_transit: 'Em trânsito',
    delivered: 'Entregue',
    proof_pending: 'Comprovação pendente',
    completed: 'Concluído',
    cancelled: 'Cancelado',
    expired: 'Expirado',
};

const roleLabels = { producer: 'Produtor', buyer: 'Comprador', carrier: 'Transportadora', ngo: 'ONG' };
const main = document.querySelector('#main-content');
const modal = document.querySelector('[data-auth-modal]');
const toastRegion = document.querySelector('.toast-region');

const sampleLots = [
    { id: 1042, product: { name: 'Tomate italiano' }, quality_grade: { name: 'Tipo B' }, quantity: '680', unit: 'kg', origin: { city: 'Mogi das Cruzes', state: 'SP' }, asking_price: '2.35', available_until: new Date(Date.now() + 86400000).toISOString(), donation_eligible: true, theme: 'tomato' },
    { id: 1041, product: { name: 'Banana-prata' }, quality_grade: { name: 'Tipo A' }, quantity: '1.2', unit: 't', origin: { city: 'Registro', state: 'SP' }, asking_price: '1.80', available_until: new Date(Date.now() + 172800000).toISOString(), donation_eligible: false, theme: '' },
    { id: 1039, product: { name: 'Couve manteiga' }, quality_grade: { name: 'Tipo B' }, quantity: '240', unit: 'kg', origin: { city: 'Ibiúna', state: 'SP' }, asking_price: '1.15', available_until: new Date(Date.now() + 21600000).toISOString(), donation_eligible: true, theme: 'green' },
    { id: 1037, product: { name: 'Batata-doce' }, quality_grade: { name: 'Tipo A' }, quantity: '830', unit: 'kg', origin: { city: 'Piedade', state: 'SP' }, asking_price: '1.65', available_until: new Date(Date.now() + 259200000).toISOString(), donation_eligible: true, theme: 'purple' },
    { id: 1035, product: { name: 'Cenoura' }, quality_grade: { name: 'Tipo B' }, quantity: '420', unit: 'kg', origin: { city: 'São Gotardo', state: 'MG' }, asking_price: '1.32', available_until: new Date(Date.now() + 129600000).toISOString(), donation_eligible: false, theme: '' },
    { id: 1032, product: { name: 'Abobrinha' }, quality_grade: { name: 'Tipo A' }, quantity: '315', unit: 'kg', origin: { city: 'Atibaia', state: 'SP' }, asking_price: '1.48', available_until: new Date(Date.now() + 64800000).toISOString(), donation_eligible: false, theme: 'green' },
];

function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, function (char) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char];
    });
}

function short(value, start = 5, end = 4) {
    if (!value || value.startsWith('Configure')) return value || 'Não configurado';
    return value.length > start + end + 3 ? value.slice(0, start) + '…' + value.slice(-end) : value;
}

function money(value) {
    return new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value || 0));
}

function toast(message, type = '') {
    const node = document.createElement('div');
    node.className = 'toast ' + type;
    node.textContent = message;
    toastRegion.appendChild(node);
    setTimeout(function () { node.remove(); }, 4200);
}

async function api(path, options = {}) {
    const headers = { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers || {}) };
    if (state.token) headers.Authorization = 'Bearer ' + state.token;
    const response = await fetch(config.apiUrl + path, { ...options, headers });
    const payload = response.status === 204 ? null : await response.json().catch(function () { return null; });
    if (!response.ok) {
        const validation = payload && payload.errors ? Object.values(payload.errors).flat()[0] : null;
        throw new Error(validation || (payload && payload.message) || 'Não foi possível concluir a solicitação.');
    }
    return payload && Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;
}

function setPage(html, title) {
    main.innerHTML = html;
    document.title = title + ' — FoodRescue';
    window.scrollTo({ top: 0, behavior: 'instant' });
    bindPageActions();
}

function renderLanding() {
    setPage(
        '<section class="hero">' +
            '<div class="hero-copy">' +
                '<span class="eyebrow">DO CAMPO AO DESTINO CERTO</span>' +
                '<h1>Alimento bom não vira <em>desperdício.</em></h1>' +
                '<p>O FoodRescue conecta excedentes agrícolas a compradores e organizações sociais, com logística coordenada e transparência na Solana.</p>' +
                '<div class="hero-actions"><a class="button button-secondary" href="#/catalogo">Explorar excedentes →</a><button class="button button-ghost" type="button" data-open-auth>Criar uma conta</button></div>' +
                '<div class="hero-proof"><div class="proof-stat"><strong>Compra ou doação</strong><span>um único fluxo</span></div><div class="proof-stat"><strong>Custódia em FRUSD</strong><span>pagamento protegido</span></div><div class="proof-stat"><strong>Proof of Rescue</strong><span>impacto verificável</span></div></div>' +
            '</div>' +
            '<div class="hero-media"><img src="/images/foodrescue-market.jpg" alt="Caixas com legumes frescos organizados em uma feira agrícola">' +
                '<div class="impact-float"><span class="icon-box">↻</span><div><small>Último resgate confirmado</small><strong>Doação entregue em Campinas, SP</strong></div><strong>420 kg</strong></div>' +
            '</div>' +
        '</section>' +
        '<section class="section section-white"><div class="shell"><div class="section-head"><div><span class="eyebrow">COMO FUNCIONA</span><h2>Uma ponte entre abundância e necessidade.</h2></div><p>Cada operação segue etapas claras, do lote publicado à entrega confirmada — comercial ou social.</p></div>' +
            '<div class="how-grid">' +
                howCard('01', 'Publique', 'Produtores informam quantidade, qualidade, prazo e modalidades de logística.') +
                howCard('02', 'Negocie', 'Compradores enviam ofertas ou fecham pelo preço disponível. ONGs aceitam doações elegíveis.') +
                howCard('03', 'Transporte', 'Transportadoras cotam o frete e cada parte acompanha coleta e entrega.') +
                howCard('04', 'Comprove', 'A liquidação e o Proof of Rescue registram o destino real dos alimentos.') +
            '</div></div></section>' +
        '<section class="section section-dark"><div class="shell"><div class="section-head"><div><span class="eyebrow eyebrow-light">FEITO PARA TODA A REDE</span><h2>Quatro painéis. Um objetivo comum.</h2></div><p>Informações e ações certas para quem produz, compra, transporta ou transforma doação em impacto.</p></div>' +
            '<div class="role-grid">' +
                roleCard('◒', 'Produtor', 'Publique excedentes, avalie propostas e recupere receita.') +
                roleCard('◎', 'Comprador', 'Descubra lotes próximos, negocie e acompanhe compras.') +
                roleCard('↗', 'Transportadora', 'Encontre rotas, envie cotações e confirme entregas.') +
                roleCard('♡', 'ONG', 'Aceite doações e emita comprovações de resgate.') +
            '</div></div></section>',
        'Alimento com destino'
    );
}

function howCard(step, title, text) {
    return '<article class="how-card"><span class="step">' + step + '</span><h3>' + title + '</h3><p>' + text + '</p></article>';
}

function roleCard(icon, title, text) {
    return '<article class="role-card"><span class="role-icon">' + icon + '</span><h3>' + title + '</h3><p>' + text + '</p></article>';
}

async function renderCatalog() {
    setPage(
        '<div class="page"><div class="shell"><div class="page-head"><div><span class="eyebrow">MARKETPLACE</span><h1>Excedentes disponíveis</h1><p>Alimentos próprios para consumo, com prazo, origem e qualidade informados pelo produtor.</p></div><button class="button" type="button" data-new-lot>+ Publicar excedente</button></div>' +
        '<div class="toolbar"><div class="field"><label for="search">Buscar produto ou cidade</label><input id="search" type="search" placeholder="Ex.: tomate ou Campinas"></div><div class="field"><label for="donation-filter">Finalidade</label><select id="donation-filter"><option value="">Todos os lotes</option><option value="true">Aceita doação</option><option value="false">Somente venda</option></select></div><div class="field"><label for="sort">Ordenar por</label><select id="sort"><option value="urgency">Mais urgente</option><option value="price_asc">Menor preço</option><option value="price_desc">Maior preço</option><option value="newest">Mais recente</option></select></div></div>' +
        '<div class="catalog-grid" data-catalog><div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div></div></div></div>',
        'Catálogo de excedentes'
    );
    try {
        const result = await api('/surplus?per_page=24&sort=urgency');
        state.catalog = Array.isArray(result) ? result : (result && result.data) || sampleLots;
    } catch (_) {
        state.catalog = sampleLots;
    }
    paintCatalog(state.catalog);
}

function paintCatalog(lots) {
    const grid = document.querySelector('[data-catalog]');
    if (!grid) return;
    if (!lots.length) {
        grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1"><h2>Nenhum excedente encontrado</h2><p>Tente remover alguns filtros ou volte mais tarde.</p></div>';
        return;
    }
    grid.innerHTML = lots.map(function (lot, index) {
        const product = lot.product?.name || lot.agricultural_product?.name || 'Produto agrícola';
        const deadlineHours = Math.max(1, Math.round((new Date(lot.available_until).getTime() - Date.now()) / 3600000));
        const theme = lot.theme || ['tomato', '', 'green', 'purple'][index % 4];
        return '<article class="lot-card" data-lot-card data-search="' + esc((product + ' ' + lot.origin?.city).toLowerCase()) + '" data-donation="' + Boolean(lot.donation_eligible) + '">' +
            '<div class="lot-visual ' + theme + '"><span class="produce-shape" aria-hidden="true"></span><span class="lot-tag">' + esc(lot.quality_grade?.name || 'Qualidade verificada') + '</span></div>' +
            '<div class="lot-body"><div class="lot-title"><h3>' + esc(product) + '</h3><div class="lot-price">' + money(lot.asking_price) + ' FRUSD<small>por ' + esc(lot.unit) + '</small></div></div>' +
            '<div class="lot-meta"><span>◉ ' + esc(lot.origin?.city || 'Origem') + ', ' + esc(lot.origin?.state || 'BR') + '</span><span>◷ ' + deadlineHours + 'h restantes</span><span>' + esc(lot.quantity) + ' ' + esc(lot.unit) + '</span></div>' +
            '<div class="lot-actions"><button class="button button-small" type="button" data-buy="' + esc(lot.id) + '">Comprar agora</button>' + (lot.donation_eligible ? '<button class="button button-ghost button-small" type="button" data-donate="' + esc(lot.id) + '">Doação</button>' : '') + '</div></div></article>';
    }).join('');
    bindCatalogActions();
}

function bindCatalogActions() {
    const search = document.querySelector('#search');
    const donation = document.querySelector('#donation-filter');
    function filter() {
        const term = search.value.trim().toLowerCase();
        document.querySelectorAll('[data-lot-card]').forEach(function (card) {
            const matchesText = !term || card.dataset.search.includes(term);
            const matchesDonation = !donation.value || card.dataset.donation === donation.value;
            card.hidden = !(matchesText && matchesDonation);
        });
    }
    search?.addEventListener('input', filter);
    donation?.addEventListener('change', filter);
    document.querySelector('#sort')?.addEventListener('change', function (event) {
        const value = event.target.value;
        const sorted = [...state.catalog].sort(function (a, b) {
            if (value === 'price_asc') return Number(a.asking_price) - Number(b.asking_price);
            if (value === 'price_desc') return Number(b.asking_price) - Number(a.asking_price);
            if (value === 'newest') return Number(b.id) - Number(a.id);
            return new Date(a.available_until) - new Date(b.available_until);
        });
        paintCatalog(sorted);
    });
    document.querySelectorAll('[data-buy]').forEach(function (button) {
        button.addEventListener('click', function () { createTrade(button.dataset.buy, false); });
    });
    document.querySelectorAll('[data-donate]').forEach(function (button) {
        button.addEventListener('click', function () { createTrade(button.dataset.donate, true); });
    });
}

async function createTrade(lotId, donation) {
    if (!state.token) {
        openAuth(donation ? 'register' : 'login');
        toast('Conecte sua carteira e entre para continuar.');
        return;
    }
    try {
        const path = donation ? '/surplus/' + lotId + '/donations/accept' : '/surplus/' + lotId + '/buy-now';
        const trade = await api(path, { method: 'POST', body: '{}' });
        sessionStorage.setItem('foodrescue_trade', JSON.stringify(trade));
        toast(donation ? 'Doação aceita. Agora defina a logística.' : 'Compra reservada. Agora defina o transporte.');
        location.hash = '#/acompanhamento';
    } catch (error) {
        toast(error.message, 'error');
    }
}

function renderDashboard() {
    const role = (state.user?.roles || [state.dashboardRole])[0];
    if (roleLabels[role]) state.dashboardRole = role;
    const profile = dashboardData(state.dashboardRole);
    setPage(
        '<div class="page"><div class="shell"><div class="page-head"><div><span class="eyebrow">VISÃO OPERACIONAL</span><h1>Painel ' + roleLabels[state.dashboardRole] + '</h1><p>Ações prioritárias, andamento das operações e impacto em um só lugar.</p></div><a class="button" href="#/catalogo">' + profile.cta + '</a></div>' +
        '<div class="dashboard-layout"><aside class="sidebar"><div class="sidebar-user"><strong>' + esc(state.user?.name || 'Conta de demonstração') + '</strong><small>' + roleLabels[state.dashboardRole] + '</small></div><nav><button class="active">⌂ Visão geral</button><button>◫ Operações</button><button>↗ Transporte</button><button>◇ Reputação</button><button>⚙ Configurações</button></nav></aside>' +
        '<section class="dashboard-main"><div class="role-switcher" aria-label="Visualizar dashboard por papel">' +
            Object.keys(roleLabels).map(function (key) { return '<button type="button" class="' + (key === state.dashboardRole ? 'active' : '') + '" data-role="' + key + '">' + roleLabels[key] + '</button>'; }).join('') +
        '</div><div class="metric-grid">' + profile.metrics.map(metricCard).join('') + '</div>' +
        '<div class="panel-grid"><article class="panel"><h2>Operações por etapa</h2><div class="status-list">' + profile.statuses.map(statusRow).join('') + '</div></article>' +
        '<article class="panel"><h2>Atividade recente</h2><div class="activity-list">' + profile.activity.map(activityItem).join('') + '</div></article></div></section></div></div></div>',
        'Dashboard ' + roleLabels[state.dashboardRole]
    );
    document.querySelectorAll('[data-role]').forEach(function (button) {
        button.addEventListener('click', function () { state.dashboardRole = button.dataset.role; renderDashboard(); });
    });
    hydrateDashboard(state.dashboardRole);
}

function dashboardData(role) {
    const data = {
        producer: { cta: '+ Publicar excedente', metrics: [['Excedentes ativos', '12', '+3 esta semana'], ['Receita recuperada', '18.420 FRUSD', '+12% no mês'], ['Alimento destinado', '8,7 t', 'venda + doação'], ['Reputação', '4,9 / 5', '37 avaliações']], statuses: [['Aguardando pagamento', 38, 3], ['Pagamento em custódia', 62, 5], ['Em trânsito', 48, 4], ['Concluído', 86, 7]], activity: [['◒', 'Tomate reservado', '680 kg', '12 min'], ['◎', 'Pagamento confirmado', '#1040', '2h'], ['♡', 'Doação concluída', '420 kg', 'ontem']] },
        buyer: { cta: 'Encontrar excedentes', metrics: [['Compras ativas', '7', '3 a caminho'], ['Economia estimada', '6.280 FRUSD', '-28% vs. mercado'], ['Volume comprado', '5,4 t', 'este mês'], ['Reputação', '4,8 / 5', '21 avaliações']], statuses: [['Aguardando pagamento', 30, 2], ['Pagamento em custódia', 72, 5], ['Em trânsito', 44, 3], ['Concluído', 90, 8]], activity: [['◎', 'Oferta aceita', 'Batata-doce', '18 min'], ['↗', 'Coleta confirmada', '#1038', '3h'], ['✓', 'Entrega concluída', '#1029', 'ontem']] },
        carrier: { cta: 'Ver rotas abertas', metrics: [['Rotas disponíveis', '18', 'até 140 km'], ['Fretes ativos', '5', '2 coletas hoje'], ['Receita em fretes', '9.840 FRUSD', '+8% no mês'], ['Reputação', '4,9 / 5', '43 avaliações']], statuses: [['Cotação enviada', 35, 4], ['Selecionado', 58, 3], ['Em trânsito', 72, 5], ['Concluído', 94, 12]], activity: [['↗', 'Nova rota próxima', '62 km', '8 min'], ['◎', 'Cotação aceita', '#1041', '1h'], ['✓', 'Entrega confirmada', '#1034', 'ontem']] },
        ngo: { cta: 'Encontrar doações', metrics: [['Doações ativas', '4', '2 a caminho'], ['Alimento resgatado', '3,2 t', 'este mês'], ['Pessoas alcançadas', '1.860', 'estimativa'], ['Proofs emitidos', '27', '100% verificados']], statuses: [['Pagamento do frete', 25, 1], ['Em trânsito', 50, 2], ['Comprovação pendente', 25, 1], ['Concluído', 88, 7]], activity: [['♡', 'Doação aceita', 'Couve, 240 kg', '24 min'], ['↗', 'Carga coletada', '#1036', '4h'], ['✓', 'Proof confirmado', '#1028', 'ontem']] },
    };
    return data[role];
}

function metricCard(item) {
    return '<article class="metric-card"><small>' + item[0] + '</small><strong>' + item[1] + '</strong><span>' + item[2] + '</span></article>';
}

function statusRow(item) {
    return '<div class="status-row"><span>' + item[0] + '</span><div class="status-bar"><i style="width:' + item[1] + '%"></i></div><strong>' + item[2] + '</strong></div>';
}

function activityItem(item) {
    return '<div class="activity-item"><span class="activity-icon">' + item[0] + '</span><div><strong>' + item[1] + '</strong><small>' + item[2] + '</small></div><small>' + item[3] + '</small></div>';
}

async function hydrateDashboard(role) {
    if (!state.token || state.user?.roles?.[0] !== role) return;
    try {
        const remote = await api('/dashboard/' + role);
        const metrics = document.querySelectorAll('.metric-card strong');
        if (!metrics.length || !remote?.summary) return;
        const values = Object.values(remote.summary).filter(function (value) { return typeof value !== 'object'; });
        values.slice(0, metrics.length).forEach(function (value, index) { metrics[index].textContent = String(value); });
    } catch (_) {
        // Dados de demonstração continuam visíveis se a API estiver indisponível.
    }
}

function renderTracking() {
    const stored = JSON.parse(sessionStorage.getItem('foodrescue_trade') || 'null');
    const trade = stored || { id: 1042, status: 'in_transit', product_amount: '1598.00', shipping_amount: '186.00', buyer_total: '1784.00', is_donation: false };
    const order = ['waiting_payment', 'funded', 'in_transit', 'delivered', 'proof_pending', 'completed'];
    const current = Math.max(0, order.indexOf(trade.status));
    setPage(
        '<div class="page"><div class="shell"><div class="page-head"><div><span class="eyebrow">RASTREABILIDADE</span><h1>Acompanhe cada etapa</h1><p>Pagamento, logística, entrega e comprovação com estados objetivos do início ao fim.</p></div></div>' +
        '<article class="timeline-card"><div class="trade-summary"><div><h2>Operação #' + esc(trade.id) + '</h2><p>' + (trade.is_donation ? 'Doação de excedente' : 'Compra de excedente') + ' · Mogi das Cruzes → Campinas</p></div><span class="status-pill">' + esc(statusLabels[trade.status] || trade.status) + '</span></div>' +
        '<div class="timeline">' + order.map(function (status, index) { return '<div class="timeline-step ' + (index < current ? 'done' : index === current ? 'active' : '') + '"><span>' + statusLabels[status] + '</span></div>'; }).join('') + '</div>' +
        '<div class="details-grid"><div class="detail"><small>Total protegido</small><strong>' + money(trade.buyer_total) + ' FRUSD</strong></div><div class="detail"><small>Transportadora</small><strong>Rota Verde Logística</strong></div><div class="detail"><small>Previsão de entrega</small><strong>Hoje, 16:30</strong></div></div>' +
        '<div class="panel-grid" style="margin-top:1rem"><div class="panel"><h3>Próxima ação</h3><p>O lote está em trânsito. A confirmação de entrega será solicitada ao destinatário e registrada antes da liquidação.</p><button class="button button-small" type="button" data-advance-status>Simular próxima etapa</button></div>' +
        '<div class="panel"><h3>Registro on-chain</h3><p>Conta da operação vinculada ao programa FoodRescue.</p><a class="button button-ghost button-small" href="' + solscanAddress(config.programId) + '" target="_blank" rel="noopener">Ver no Solscan ↗</a></div></div>' +
        '</article></div></div>',
        'Acompanhar entrega'
    );
    document.querySelector('[data-advance-status]')?.addEventListener('click', function () {
        const next = order[Math.min(current + 1, order.length - 1)];
        trade.status = next;
        sessionStorage.setItem('foodrescue_trade', JSON.stringify(trade));
        renderTracking();
        toast('Estado atualizado para “' + statusLabels[next] + '”.');
    });
}

function renderDonations() {
    setPage(
        '<div class="page"><div class="shell"><section class="donation-hero"><div class="donation-copy"><span class="eyebrow eyebrow-light">IMPACTO COMPROVADO</span><h1>Resgatar é dar destino — e deixar prova.</h1><p>ONGs aceitam lotes elegíveis, coordenam o transporte e registram a destinação dos alimentos. O Proof of Rescue torna cada entrega verificável.</p><div><a class="button button-secondary" href="#/catalogo">Ver lotes para doação →</a></div></div>' +
        '<article class="proof-card"><div class="proof-seal">✓</div><small>PROOF OF RESCUE</small><h2>Resgate #FR-1028</h2><p>Entrega social confirmada e registrada na Solana Devnet.</p><dl><div><dt>Alimento resgatado</dt><dd>420 kg</dd></div><div><dt>Beneficiário</dt><dd>Instituto Mesa Aberta</dd></div><div><dt>Confirmado em</dt><dd>06 set 2026</dd></div><div><dt>Status</dt><dd>Completed</dd></div></dl><a class="button button-ghost button-small" href="' + solscanAddress(config.programId) + '" target="_blank" rel="noopener">Ver registro no Solscan ↗</a></article></section>' +
        '<section class="section"><div class="section-head"><div><span class="eyebrow">FLUXO DE DOAÇÃO</span><h2>Da oferta ao Proof of Rescue.</h2></div><p>O estado <strong>proof_pending</strong> garante que uma doação entregue só seja concluída após a comprovação pela organização beneficiária.</p></div>' +
        '<div class="how-grid">' + howCard('01', 'Lote elegível', 'O produtor marca o excedente como disponível para doação.') + howCard('02', 'ONG aceita', 'A organização assume o recebimento e define a logística.') + howCard('03', 'Entrega', 'A carga percorre os estados funded, in_transit e delivered.') + howCard('04', 'Proof', 'A ONG confirma o resgate e a operação passa a completed.') + '</div></section></div></div>',
        'Doações e Proof of Rescue'
    );
}

function solscanAddress(address) {
    if (!address || address.startsWith('Configure')) return config.explorer + '/?cluster=' + encodeURIComponent(config.network);
    return config.explorer + '/account/' + encodeURIComponent(address) + '?cluster=' + encodeURIComponent(config.network);
}

function renderNetwork() {
    setPage(
        '<div class="page"><div class="shell"><div class="page-head"><div><span class="eyebrow">TRANSPARÊNCIA</span><h1>Infraestrutura Solana</h1><p>Endereços públicos do MVP. Confira programa, token de liquidação e operações diretamente no explorer.</p></div><span class="network-chip"><i></i> Solana ' + esc(config.network) + '</span></div>' +
        '<div class="chain-grid"><article class="chain-card featured"><span class="eyebrow eyebrow-light">PROGRAMA</span><h2>FoodRescue Program</h2><p>Gerencia custódia, estados da operação, entrega, liquidação e registro do Proof of Rescue.</p><div class="address"><span>' + esc(short(config.programId, 10, 8)) + '</span><a href="' + solscanAddress(config.programId) + '" target="_blank" rel="noopener">Solscan ↗</a></div></article>' +
        '<article class="chain-card"><span class="eyebrow">TOKEN DO MVP</span><h2>FRUSD Mint</h2><p>Token de teste usado para pagamentos e fretes na Devnet. Não possui valor monetário real.</p><div class="address"><span>' + esc(short(config.frusdMint, 10, 8)) + '</span><a href="' + solscanAddress(config.frusdMint) + '" target="_blank" rel="noopener">Solscan ↗</a></div></article></div>' +
        '<section class="section"><div class="section-head"><div><span class="eyebrow">ESTADOS DO CONTRATO</span><h2>O que acontece com o pagamento?</h2></div><p>O front-end reflete a máquina de estados já implementada no backend e no programa.</p></div>' +
        '<div class="how-grid">' + howCard('01', 'waiting_payment', 'Operação criada; o comprador ainda precisa financiar a custódia.') + howCard('02', 'funded', 'FRUSD bloqueado pelo programa até a conclusão das condições.') + howCard('03', 'delivered', 'Destinatário confirmou que recebeu a carga.') + howCard('04', 'completed', 'Valores liquidados e operação encerrada on-chain.') + '</div></section></div></div>',
        'Rede Solana'
    );
}

function openAuth(tab = 'login') {
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    renderAuthPanels();
    selectAuthTab(tab);
    setTimeout(function () { modal.querySelector('button, input')?.focus(); }, 0);
}

function closeAuth() {
    modal.hidden = true;
    document.body.style.overflow = '';
}

function selectAuthTab(tab) {
    document.querySelectorAll('[data-auth-tab]').forEach(function (button) {
        const active = button.dataset.authTab === tab;
        button.classList.toggle('active', active);
        button.setAttribute('aria-selected', String(active));
    });
    document.querySelectorAll('[data-auth-panel]').forEach(function (panel) { panel.hidden = panel.dataset.authPanel !== tab; });
}

function walletCard() {
    const address = state.wallet ? short(state.wallet, 7, 6) : 'Phantom ou carteira compatível';
    return '<div class="wallet-connect-card"><strong>' + (state.wallet ? 'Carteira conectada: ' + esc(address) : 'Conecte sua carteira Solana') + '</strong><p>Compartilhamos somente o endereço público. Para o cadastro, você assinará uma mensagem de comprovação.</p><button class="button button-ghost button-full" type="button" data-connect-wallet>' + (state.wallet ? 'Trocar carteira' : 'Conectar carteira') + '</button></div>';
}

function renderAuthPanels() {
    const login = document.querySelector('[data-auth-panel="login"]');
    const register = document.querySelector('[data-auth-panel="register"]');
    login.innerHTML = walletCard() + '<div class="form-divider">acesso à conta</div><form class="form-grid" data-login-form><div class="field"><label for="login-email">E-mail</label><input id="login-email" name="email" type="email" autocomplete="email" required></div><div class="field"><label for="login-password">Senha</label><input id="login-password" name="password" type="password" autocomplete="current-password" required></div><button class="button button-full" type="submit">Entrar no FoodRescue</button><p class="form-message" data-form-message></p></form>';
    register.innerHTML = walletCard() + '<form class="form-grid" data-register-form><div class="field-row"><div class="field"><label for="reg-name">Nome</label><input id="reg-name" name="name" required maxlength="120"></div><div class="field"><label for="reg-email">E-mail</label><input id="reg-email" name="email" type="email" required></div></div><div class="field"><label for="reg-role">Como você participa?</label><select id="reg-role" name="role"><option value="producer">Produtor</option><option value="buyer">Comprador</option><option value="carrier">Transportadora</option><option value="ngo">ONG / Instituição social</option></select></div><div data-profile-fields></div><div class="field-row"><div class="field"><label for="reg-password">Senha</label><input id="reg-password" name="password" type="password" autocomplete="new-password" required minlength="12"></div><div class="field"><label for="reg-password-confirmation">Confirmar senha</label><input id="reg-password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div></div><button class="button button-full" type="submit">Assinar e criar conta</button><p class="form-message" data-form-message></p></form>';
    bindAuthForms();
    paintProfileFields('producer');
}

function paintProfileFields(role) {
    const target = document.querySelector('[data-profile-fields]');
    if (!target) return;
    let specific = '';
    if (role === 'producer') specific = '<div class="field-row"><div class="field"><label>Tipo de produtor</label><select name="producer_type"><option value="individual">Pessoa física</option><option value="company">Empresa</option><option value="cooperative">Cooperativa</option></select></div><div class="field"><label>Nome da propriedade</label><input name="farm_name"></div></div><div class="field"><label>Organização (empresa ou cooperativa)</label><input name="organization_name"></div>';
    if (role === 'buyer') specific = '<div class="field-row"><div class="field"><label>Tipo de comprador</label><select name="buyer_type"><option value="individual">Pessoa física</option><option value="company">Empresa</option></select></div><div class="field"><label>Organização (se empresa)</label><input name="organization_name"></div></div>';
    if (role === 'carrier') specific = '<div class="field-row"><div class="field"><label>Empresa</label><input name="company_name" required></div><div class="field"><label>Responsável</label><input name="contact_name" required></div></div><div class="field"><label>Regiões atendidas</label><input name="service_regions" placeholder="São Paulo, Campinas" required></div>';
    if (role === 'ngo') specific = '<div class="field-row"><div class="field"><label>Organização</label><input name="organization_name" required></div><div class="field"><label>Número de registro</label><input name="registration_number" required></div></div><div class="field"><label>Responsável</label><input name="contact_name" required></div>';
    target.innerHTML = specific + '<div class="field-row"><div class="field"><label>Telefone</label><input name="phone" required></div><div class="field"><label>Documento</label><input name="document_number"' + (role === 'ngo' ? '' : ' required') + '></div></div><div class="field-row"><div class="field"><label>Cidade</label><input name="city" required></div><div class="field"><label>Estado</label><input name="state" required maxlength="100"></div></div><div class="field"><label>Endereço</label><input name="address_line" required></div>';
}

function bindAuthForms() {
    document.querySelectorAll('[data-connect-wallet]').forEach(function (button) { button.addEventListener('click', connectWallet); });
    document.querySelector('#reg-role')?.addEventListener('change', function (event) { paintProfileFields(event.target.value); });
    document.querySelector('[data-login-form]')?.addEventListener('submit', handleLogin);
    document.querySelector('[data-register-form]')?.addEventListener('submit', handleRegister);
}

async function connectWallet() {
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

function uint8ToBase64(bytes) {
    let binary = '';
    bytes.forEach(function (byte) { binary += String.fromCharCode(byte); });
    return btoa(binary);
}

async function handleLogin(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const message = form.querySelector('[data-form-message]');
    const button = form.querySelector('[type="submit"]');
    message.textContent = '';
    button.disabled = true;
    try {
        const fields = new FormData(form);
        const result = await api('/auth/login', { method: 'POST', body: JSON.stringify({ email: fields.get('email'), password: fields.get('password'), device_name: 'foodrescue-web' }) });
        state.token = result.token;
        state.user = result.user;
        sessionStorage.setItem('foodrescue_token', result.token);
        sessionStorage.setItem('foodrescue_user', JSON.stringify(result.user));
        updateWalletButton();
        closeAuth();
        toast('Bem-vindo ao FoodRescue.');
        location.hash = '#/dashboard';
    } catch (error) {
        message.textContent = error.message;
    } finally {
        button.disabled = false;
    }
}

async function handleRegister(event) {
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

function updateWalletButton() {
    document.querySelectorAll('[data-open-auth]').forEach(function (button) {
        button.textContent = state.user ? short(state.user.solana_wallet_address || state.wallet || state.user.name, 6, 4) : 'Conectar carteira';
    });
}

function bindPageActions() {
    document.querySelectorAll('[data-open-auth]').forEach(function (button) { button.addEventListener('click', function () { openAuth('register'); }); });
    document.querySelector('[data-new-lot]')?.addEventListener('click', function () {
        if (!state.token) openAuth('register'); else toast('Formulário de publicação será conectado ao endpoint POST /surplus.');
    });
}

function route() {
    const path = location.hash.replace(/^#\/?/, '').split('?')[0] || '';
    document.querySelectorAll('[data-nav]').forEach(function (link) { link.classList.toggle('active', link.dataset.nav === path); });
    document.querySelector('#mobile-nav').hidden = true;
    document.querySelector('.menu-button').setAttribute('aria-expanded', 'false');
    if (path === 'catalogo') return renderCatalog();
    if (path === 'dashboard') return renderDashboard();
    if (path === 'acompanhamento') return renderTracking();
    if (path === 'doacoes') return renderDonations();
    if (path === 'rede') return renderNetwork();
    renderLanding();
}

document.querySelector('.menu-button').addEventListener('click', function (event) {
    const nav = document.querySelector('#mobile-nav');
    nav.hidden = !nav.hidden;
    event.currentTarget.setAttribute('aria-expanded', String(!nav.hidden));
});
document.querySelectorAll('[data-open-auth]').forEach(function (button) { button.addEventListener('click', function () { openAuth('login'); }); });
document.querySelector('[data-close-auth]').addEventListener('click', closeAuth);
modal.addEventListener('click', function (event) { if (event.target === modal) closeAuth(); });
document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && !modal.hidden) closeAuth(); });
document.querySelectorAll('[data-auth-tab]').forEach(function (button) { button.addEventListener('click', function () { selectAuthTab(button.dataset.authTab); }); });
window.addEventListener('hashchange', route);
updateWalletButton();
route();
