import { api } from '../core/api.js';
import { config } from '../core/config.js';
import { esc, formatDateTime } from '../core/format.js';
import { state } from '../core/state.js';
import { chainCard, howCard, setPage } from '../core/ui.js';

export async function renderNetwork() {
    setPage(
        '<div class="page network-page"><div class="shell"><div class="page-head network-page-head"><div><span class="eyebrow">TRANSPARÊNCIA</span><h1>Confiança que pode ser conferida.</h1><p>A rede FoodRescue mantém os principais registros públicos para que produtores, compradores e organizações sociais acompanhem cada operação com clareza.</p></div><span class="network-chip"><i></i> Rede de verificação · ' + esc(config.network) + '</span></div>' +
        '<div class="network-intro"><span class="network-intro-icon" aria-hidden="true">◎</span><div><strong>Uma camada de confiança para cada resgate.</strong><p>Os registros abaixo ajudam a confirmar que o fluxo aconteceu como combinado, sem exigir conhecimento técnico para acompanhar o impacto.</p></div></div>' +
        '<div data-chain><div class="skeleton" style="min-height:180px"></div></div>' +
        '<section class="section network-flow"><div class="section-head"><div><span class="eyebrow">COMO FUNCIONA</span><h2>Do acordo à entrega confirmada.</h2></div><p>O valor fica protegido durante o percurso e só é liberado quando as condições da operação são cumpridas.</p></div>' +
        '<div class="how-grid">' + howCard('01', 'Acordo confirmado', 'A operação é criada com os dados do lote, das pessoas envolvidas e da entrega.') + howCard('02', 'Pagamento protegido', 'O valor fica reservado enquanto a operação segue seu caminho.') + howCard('03', 'Entrega reconhecida', 'Quem recebe confirma que a carga chegou ao destino combinado.') + howCard('04', 'Impacto registrado', 'A operação é concluída e o resultado fica disponível para consulta.') + '</div></section></div></div>',
        'Rede Solana',
    );
    paintChainCards();
}

export async function paintChainCards() {
    const target = document.querySelector('[data-chain]');
    if (!target) return;

    let protocol = null;
    if (state.token) {
        try {
            protocol = await api('/blockchain/protocol');
        } catch (_) {
            protocol = null;
        }
    }

    const programId = protocol?.program_id || config.programId;
    const mint = protocol?.mint || config.frusdMint;

    target.innerHTML = '<div class="chain-grid">' +
        chainCard('PROGRAMA', 'FoodRescue Program', 'Onde ficam registradas as etapas importantes de uma operação, da proteção do pagamento à confirmação da entrega.', programId, true) +
        chainCard('TOKEN DO MVP', 'FRUSD Mint', 'Unidade de teste usada para representar pagamentos e fretes neste MVP. Não possui valor monetário real.', mint, false) +
        (protocol ? chainCard('REGRAS DA REDE', 'Parâmetros de confiança', 'Referência pública das regras que mantêm pagamentos, taxas e registros alinhados durante a operação.', protocol.config_pda, false) : '') +
        (protocol ? chainCard('DESTINO DAS TAXAS', 'Manutenção do protocolo', 'Endereço público que recebe a taxa das operações comerciais e ajuda a sustentar a rede.', protocol.treasury_wallet, false) : '') +
        '</div>' +
        (protocol
            ? '<p class="footer-note network-footnote">Última verificação pública em ' + esc(formatDateTime(protocol.confirmed_at)) + '. <a href="' + config.explorer + '/?cluster=' + encodeURIComponent(protocol.cluster) + '" target="_blank" rel="noopener">Abrir explorador ↗</a></p>'
            : '<p class="footer-note network-footnote">' + (state.token ? 'A configuração pública ainda está sendo preparada; os endereços acima vêm do ambiente atual.' : 'Entre na sua conta para consultar a configuração pública confirmada.') + '</p>');
}
