import { howCard, roleCard, setPage } from '../core/ui.js';

export function renderLanding() {
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
                '<div class="impact-float"><span class="icon-box">↻</span><div><small>Proof of Rescue</small><strong>Cada doação entregue vira registro on-chain</strong></div><a href="#/doacoes">Ver →</a></div>' +
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
