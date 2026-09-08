import { api } from '../core/api.js';
import { config } from '../core/config.js';
import { esc, formatDateTime } from '../core/format.js';
import { state } from '../core/state.js';
import { chainCard, howCard, setPage } from '../core/ui.js';

export async function renderNetwork() {
    setPage(
        '<div class="page"><div class="shell"><div class="page-head"><div><span class="eyebrow">TRANSPARÊNCIA</span><h1>Infraestrutura Solana</h1><p>Endereços públicos do MVP. Confira programa, token de liquidação e operações diretamente no explorer.</p></div><span class="network-chip"><i></i> Solana ' + esc(config.network) + '</span></div>' +
        '<div data-chain><div class="skeleton" style="min-height:180px"></div></div>' +
        '<section class="section"><div class="section-head"><div><span class="eyebrow">ESTADOS DO CONTRATO</span><h2>O que acontece com o pagamento?</h2></div><p>O front-end reflete a máquina de estados já implementada no backend e no programa.</p></div>' +
        '<div class="how-grid">' + howCard('01', 'waiting_payment', 'Operação criada; o comprador ainda precisa financiar a custódia.') + howCard('02', 'funded', 'FRUSD bloqueado pelo programa até a conclusão das condições.') + howCard('03', 'delivered', 'Destinatário confirmou que recebeu a carga.') + howCard('04', 'completed', 'Valores liquidados e operação encerrada on-chain.') + '</div></section></div></div>',
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
        chainCard('PROGRAMA', 'FoodRescue Program', 'Gerencia custódia, estados da operação, entrega, liquidação e registro do Proof of Rescue.', programId, true) +
        chainCard('TOKEN DO MVP', 'FRUSD Mint', 'Token de teste usado para pagamentos e fretes. Não possui valor monetário real.', mint, false) +
        (protocol ? chainCard('CONFIGURAÇÃO', 'ProtocolConfig PDA', 'Conta de configuração que define treasury e mint válidos para a liquidação.', protocol.config_pda, false) : '') +
        (protocol ? chainCard('TESOURARIA', 'Treasury', 'Carteira que recebe a taxa do protocolo em cada liquidação comercial.', protocol.treasury_wallet, false) : '') +
        '</div>' +
        (protocol
            ? '<p class="footer-note">Configuração on-chain versão ' + esc(protocol.version) + ', confirmada em ' + esc(formatDateTime(protocol.confirmed_at)) + ' no cluster ' + esc(protocol.cluster) + '.</p>'
            : '<p class="footer-note">' + (state.token ? 'O ProtocolConfig ainda não foi inicializado on-chain; os endereços acima vêm da configuração do ambiente.' : 'Entre na sua conta para ver a configuração confirmada on-chain.') + '</p>');
}
