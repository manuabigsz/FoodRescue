//! Testes do programa FoodRescue que rodam sem validador nem RPC.
//!
//! Cobrem três coisas que antes só tinham revisão humana: a serialização dos
//! estados, o parsing das instruções e as duas instruções que não fazem CPI —
//! `advance_delivery` e `confirm_rescue_proof` —, chamadas direto pelo
//! `Processor::process` com contas montadas à mão.
//!
//! As instruções que criam contas ou transferem tokens (`initialize_trade`,
//! `fund_trade`, `settle_trade`, `cancel_trade`, `create_rescue_proof`) fazem
//! CPI e precisariam do runtime; ficam fora daqui.

use foodrescue::error::FoodRescueError;
use foodrescue::instruction::FoodRescueInstruction;
use foodrescue::processor::Processor;
use foodrescue::state::{ProtocolConfig, RescueProofState, TradeState};
use solana_program::account_info::AccountInfo;
use solana_program::program_error::ProgramError;
use solana_program::pubkey::Pubkey;

fn key(byte: u8) -> Pubkey {
    Pubkey::new_from_array([byte; 32])
}

fn erro(codigo: FoodRescueError) -> ProgramError {
    ProgramError::Custom(codigo as u32)
}

fn trade_state(buyer: Pubkey, producer: Pubkey, carrier: Pubkey, bump: u8, status: u8) -> TradeState {
    TradeState {
        version: TradeState::VERSION,
        trade_bump: bump,
        vault_bump: 250,
        trade_id: 42,
        buyer,
        producer,
        carrier,
        mint: key(4),
        vault: key(5),
        protocol_config: key(6),
        product_amount: 100_000_000,
        shipping_amount: 5_000_000,
        protocol_fee: 2_000_000,
        total_amount: 105_000_000,
        expires_at: 2_000_000_000,
        status,
    }
}

fn rescue_state(ngo: Pubkey, producer: Pubkey, bump: u8, status: u8) -> RescueProofState {
    RescueProofState {
        version: RescueProofState::VERSION,
        bump,
        trade_id: 42,
        producer,
        ngo,
        carrier: key(3),
        metadata_hash: [10; 32],
        created_at: 2_000_000_001,
        status,
    }
}

fn trade_pda(program_id: &Pubkey, trade_id: u64, buyer: &Pubkey) -> (Pubkey, u8) {
    Pubkey::find_program_address(
        &[
            Processor::TRADE_SEED,
            &trade_id.to_le_bytes(),
            buyer.as_ref(),
        ],
        program_id,
    )
}

fn rescue_pda(program_id: &Pubkey, trade_id: u64, ngo: &Pubkey, producer: &Pubkey) -> (Pubkey, u8) {
    Pubkey::find_program_address(
        &[
            Processor::RESCUE_SEED,
            &trade_id.to_le_bytes(),
            ngo.as_ref(),
            producer.as_ref(),
        ],
        program_id,
    )
}

// ---------------------------------------------------------------- serialização

#[test]
fn trade_state_sobrevive_ao_round_trip() {
    let original = trade_state(key(1), key(2), key(3), 254, TradeState::STATUS_FUNDED);
    let mut raw = vec![0u8; TradeState::LEN];
    original.pack(&mut raw).unwrap();

    let lido = TradeState::unpack(&raw).unwrap();

    assert_eq!(lido.trade_id, 42);
    assert_eq!(lido.buyer, key(1));
    assert_eq!(lido.producer, key(2));
    assert_eq!(lido.carrier, key(3));
    assert_eq!(lido.total_amount, 105_000_000);
    assert_eq!(lido.expires_at, 2_000_000_000);
    assert_eq!(lido.status, TradeState::STATUS_FUNDED);
    assert_eq!(lido.trade_bump, 254);
}

#[test]
fn rescue_proof_guarda_o_status_da_atestacao() {
    let original = rescue_state(key(1), key(2), 249, RescueProofState::STATUS_PENDING_PRODUCER);
    let mut raw = vec![0u8; RescueProofState::LEN];
    original.pack(&mut raw).unwrap();

    let lido = RescueProofState::unpack(&raw).unwrap();

    assert_eq!(RescueProofState::LEN, 147);
    assert_eq!(lido.version, 2);
    assert_eq!(lido.ngo, key(1));
    assert_eq!(lido.producer, key(2));
    assert_eq!(lido.metadata_hash, [10; 32]);
    assert_eq!(lido.status, RescueProofState::STATUS_PENDING_PRODUCER);
}

#[test]
fn rescue_proof_recusa_o_layout_da_versao_anterior() {
    let original = rescue_state(key(1), key(2), 249, RescueProofState::STATUS_CONFIRMED);
    let mut raw = vec![0u8; RescueProofState::LEN];
    original.pack(&mut raw).unwrap();

    // Versão 1 tinha 146 bytes e nenhum status: aceitar esse layout deixaria uma
    // atestação antiga passar por confirmada.
    raw[0] = 1;
    assert_eq!(RescueProofState::unpack(&raw), Err(erro(FoodRescueError::InvalidState)));
    assert_eq!(
        RescueProofState::unpack(&raw[..146]),
        Err(erro(FoodRescueError::InvalidState))
    );
}

#[test]
fn protocol_config_sobrevive_ao_round_trip() {
    let original = ProtocolConfig {
        version: 1,
        bump: 250,
        authority: key(7),
        treasury: key(8),
        mint: key(4),
    };
    let mut raw = vec![0u8; ProtocolConfig::LEN];
    original.pack(&mut raw).unwrap();

    let lido = ProtocolConfig::unpack(&raw).unwrap();

    assert_eq!(lido.authority, key(7));
    assert_eq!(lido.treasury, key(8));
    assert_eq!(lido.mint, key(4));
}

// ----------------------------------------------------------------- instruções

#[test]
fn confirm_rescue_proof_e_a_tag_9_sem_payload() {
    assert!(matches!(
        FoodRescueInstruction::unpack(&[9]).unwrap(),
        FoodRescueInstruction::ConfirmRescueProof
    ));
    assert!(FoodRescueInstruction::unpack(&[9, 0]).is_err());
}

#[test]
fn create_rescue_proof_le_trade_id_carrier_e_hash() {
    let mut data = vec![5u8];
    data.extend_from_slice(&42u64.to_le_bytes());
    data.extend_from_slice(key(3).as_ref());
    data.extend_from_slice(&[10u8; 32]);

    match FoodRescueInstruction::unpack(&data).unwrap() {
        FoodRescueInstruction::CreateRescueProof {
            trade_id,
            carrier,
            metadata_hash,
        } => {
            assert_eq!(trade_id, 42);
            assert_eq!(carrier, key(3));
            assert_eq!(metadata_hash, [10; 32]);
        }
        _ => panic!("tag 5 deveria virar CreateRescueProof"),
    }
}

#[test]
fn instrucao_desconhecida_ou_vazia_e_recusada() {
    assert!(FoodRescueInstruction::unpack(&[]).is_err());
    assert!(FoodRescueInstruction::unpack(&[99]).is_err());
    // Tags sem payload não aceitam bytes extras.
    assert!(FoodRescueInstruction::unpack(&[1, 7]).is_err());
}

// ---------------------------------------------------------------------- seeds

#[test]
fn o_pda_do_trade_depende_do_comprador() {
    let program_id = key(9);

    let (pda_a, _) = trade_pda(&program_id, 42, &key(1));
    let (pda_b, _) = trade_pda(&program_id, 42, &key(2));

    // É isto que impede um terceiro de prever o ID sequencial e ocupar o PDA:
    // com outra carteira, o endereço derivado é outro.
    assert_ne!(pda_a, pda_b);
}

#[test]
fn o_pda_do_proof_depende_das_duas_partes() {
    let program_id = key(9);

    let (base, _) = rescue_pda(&program_id, 42, &key(1), &key(2));
    let (outra_ngo, _) = rescue_pda(&program_id, 42, &key(11), &key(2));
    let (outro_produtor, _) = rescue_pda(&program_id, 42, &key(1), &key(22));

    assert_ne!(base, outra_ngo);
    assert_ne!(base, outro_produtor);
}

#[test]
fn a_taxa_do_protocolo_e_dois_por_cento() {
    assert_eq!(Processor::protocol_fee(100_000_000), 2_000_000);
    assert_eq!(Processor::protocol_fee(0), 0);
    // Divisão inteira: a taxa nunca arredonda para cima.
    assert_eq!(Processor::protocol_fee(49), 0);
    assert_eq!(Processor::protocol_fee(50), 1);
}

// ------------------------------------------------- confirm_rescue_proof (tag 9)

/// Monta as contas de `confirm_rescue_proof` e executa a instrução.
fn confirmar_proof(
    program_id: &Pubkey,
    produtor: &Pubkey,
    produtor_assina: bool,
    pda: &Pubkey,
    dono_do_pda: &Pubkey,
    dados: &mut [u8],
) -> Result<(), ProgramError> {
    let mut lamports_produtor = 0u64;
    let mut lamports_pda = 1_000_000u64;
    let mut vazio: [u8; 0] = [];
    let system = Pubkey::default();

    let contas = [
        AccountInfo::new(
            produtor,
            produtor_assina,
            false,
            &mut lamports_produtor,
            &mut vazio,
            &system,
            false,
            0,
        ),
        AccountInfo::new(
            pda,
            false,
            true,
            &mut lamports_pda,
            dados,
            dono_do_pda,
            false,
            0,
        ),
    ];

    Processor::process(program_id, &contas, &[9])
}

#[test]
fn o_produtor_confirma_a_atestacao_aberta_pela_ngo() {
    let program_id = key(9);
    let (ngo, produtor) = (key(1), key(2));
    let (pda, bump) = rescue_pda(&program_id, 42, &ngo, &produtor);

    let mut dados = vec![0u8; RescueProofState::LEN];
    rescue_state(ngo, produtor, bump, RescueProofState::STATUS_PENDING_PRODUCER)
        .pack(&mut dados)
        .unwrap();

    confirmar_proof(&program_id, &produtor, true, &pda, &program_id, &mut dados).unwrap();

    let depois = RescueProofState::unpack(&dados).unwrap();
    assert_eq!(depois.status, RescueProofState::STATUS_CONFIRMED);
    // Nada além do status muda.
    assert_eq!(depois.ngo, ngo);
    assert_eq!(depois.producer, produtor);
    assert_eq!(depois.metadata_hash, [10; 32]);
}

#[test]
fn a_confirmacao_exige_a_assinatura_do_produtor() {
    let program_id = key(9);
    let (ngo, produtor) = (key(1), key(2));
    let (pda, bump) = rescue_pda(&program_id, 42, &ngo, &produtor);

    let mut dados = vec![0u8; RescueProofState::LEN];
    rescue_state(ngo, produtor, bump, RescueProofState::STATUS_PENDING_PRODUCER)
        .pack(&mut dados)
        .unwrap();

    let resultado = confirmar_proof(&program_id, &produtor, false, &pda, &program_id, &mut dados);

    assert_eq!(resultado, Err(erro(FoodRescueError::InvalidAccount)));
    assert_eq!(
        RescueProofState::unpack(&dados).unwrap().status,
        RescueProofState::STATUS_PENDING_PRODUCER
    );
}

#[test]
fn outra_carteira_nao_confirma_a_atestacao_alheia() {
    let program_id = key(9);
    let (ngo, produtor) = (key(1), key(2));
    let (pda, bump) = rescue_pda(&program_id, 42, &ngo, &produtor);

    let mut dados = vec![0u8; RescueProofState::LEN];
    rescue_state(ngo, produtor, bump, RescueProofState::STATUS_PENDING_PRODUCER)
        .pack(&mut dados)
        .unwrap();

    let intruso = key(77);
    let resultado = confirmar_proof(&program_id, &intruso, true, &pda, &program_id, &mut dados);

    assert_eq!(resultado, Err(erro(FoodRescueError::InvalidAccount)));
}

#[test]
fn a_atestacao_nao_e_confirmada_duas_vezes() {
    let program_id = key(9);
    let (ngo, produtor) = (key(1), key(2));
    let (pda, bump) = rescue_pda(&program_id, 42, &ngo, &produtor);

    let mut dados = vec![0u8; RescueProofState::LEN];
    rescue_state(ngo, produtor, bump, RescueProofState::STATUS_CONFIRMED)
        .pack(&mut dados)
        .unwrap();

    let resultado = confirmar_proof(&program_id, &produtor, true, &pda, &program_id, &mut dados);

    assert_eq!(resultado, Err(erro(FoodRescueError::InvalidState)));
}

#[test]
fn a_confirmacao_recusa_um_pda_que_nao_vem_das_seeds() {
    let program_id = key(9);
    let (ngo, produtor) = (key(1), key(2));
    let (_, bump) = rescue_pda(&program_id, 42, &ngo, &produtor);

    let mut dados = vec![0u8; RescueProofState::LEN];
    rescue_state(ngo, produtor, bump, RescueProofState::STATUS_PENDING_PRODUCER)
        .pack(&mut dados)
        .unwrap();

    // Endereço de outra dupla, com o estado da dupla certa.
    let (pda_alheio, _) = rescue_pda(&program_id, 42, &key(11), &key(22));
    let resultado = confirmar_proof(&program_id, &produtor, true, &pda_alheio, &program_id, &mut dados);

    assert_eq!(resultado, Err(erro(FoodRescueError::InvalidPda)));
}

#[test]
fn a_confirmacao_recusa_conta_de_outro_programa() {
    let program_id = key(9);
    let (ngo, produtor) = (key(1), key(2));
    let (pda, bump) = rescue_pda(&program_id, 42, &ngo, &produtor);

    let mut dados = vec![0u8; RescueProofState::LEN];
    rescue_state(ngo, produtor, bump, RescueProofState::STATUS_PENDING_PRODUCER)
        .pack(&mut dados)
        .unwrap();

    let outro_programa = key(88);
    let resultado = confirmar_proof(&program_id, &produtor, true, &pda, &outro_programa, &mut dados);

    assert_eq!(resultado, Err(erro(FoodRescueError::InvalidAccount)));
}

// ------------------------------------------------- advance_delivery (tags 6-8)

/// Monta as contas de uma transição de entrega e executa a instrução `tag`.
fn avancar_entrega(
    program_id: &Pubkey,
    ator: &Pubkey,
    pda: &Pubkey,
    dados: &mut [u8],
    tag: u8,
) -> Result<(), ProgramError> {
    let mut lamports_ator = 0u64;
    let mut lamports_pda = 1_000_000u64;
    let mut vazio: [u8; 0] = [];
    let system = Pubkey::default();

    let contas = [
        AccountInfo::new(ator, true, false, &mut lamports_ator, &mut vazio, &system, false, 0),
        AccountInfo::new(pda, false, true, &mut lamports_pda, dados, program_id, false, 0),
    ];

    Processor::process(program_id, &contas, &[tag])
}

#[test]
fn o_produtor_libera_a_carga_para_coleta() {
    let program_id = key(9);
    let (comprador, produtor) = (key(1), key(2));
    let (pda, bump) = trade_pda(&program_id, 42, &comprador);

    let mut dados = vec![0u8; TradeState::LEN];
    trade_state(comprador, produtor, key(3), bump, TradeState::STATUS_FUNDED)
        .pack(&mut dados)
        .unwrap();

    avancar_entrega(&program_id, &produtor, &pda, &mut dados, 6).unwrap();

    assert_eq!(
        TradeState::unpack(&dados).unwrap().status,
        TradeState::STATUS_READY_FOR_PICKUP
    );
}

#[test]
fn o_comprador_nao_libera_a_carga_no_lugar_do_produtor() {
    let program_id = key(9);
    let (comprador, produtor) = (key(1), key(2));
    let (pda, bump) = trade_pda(&program_id, 42, &comprador);

    let mut dados = vec![0u8; TradeState::LEN];
    trade_state(comprador, produtor, key(3), bump, TradeState::STATUS_FUNDED)
        .pack(&mut dados)
        .unwrap();

    let resultado = avancar_entrega(&program_id, &comprador, &pda, &mut dados, 6);

    assert_eq!(resultado, Err(erro(FoodRescueError::InvalidAccount)));
}

#[test]
fn a_transicao_recusa_um_estado_fora_de_ordem() {
    let program_id = key(9);
    let (comprador, produtor) = (key(1), key(2));
    let (pda, bump) = trade_pda(&program_id, 42, &comprador);

    let mut dados = vec![0u8; TradeState::LEN];
    // Ainda em INITIALIZED: liberar para coleta exige FUNDED.
    trade_state(comprador, produtor, key(3), bump, TradeState::STATUS_INITIALIZED)
        .pack(&mut dados)
        .unwrap();

    let resultado = avancar_entrega(&program_id, &produtor, &pda, &mut dados, 6);

    assert_eq!(resultado, Err(erro(FoodRescueError::InvalidState)));
}

#[test]
fn sem_transportadora_quem_coleta_e_o_destinatario() {
    let program_id = key(9);
    let (comprador, produtor) = (key(1), key(2));
    let (pda, bump) = trade_pda(&program_id, 42, &comprador);

    let mut dados = vec![0u8; TradeState::LEN];
    trade_state(
        comprador,
        produtor,
        Pubkey::default(),
        bump,
        TradeState::STATUS_READY_FOR_PICKUP,
    )
    .pack(&mut dados)
    .unwrap();

    avancar_entrega(&program_id, &comprador, &pda, &mut dados, 7).unwrap();

    assert_eq!(
        TradeState::unpack(&dados).unwrap().status,
        TradeState::STATUS_IN_TRANSIT
    );
}

#[test]
fn com_transportadora_o_destinatario_nao_confirma_a_coleta() {
    let program_id = key(9);
    let (comprador, produtor, transportadora) = (key(1), key(2), key(3));
    let (pda, bump) = trade_pda(&program_id, 42, &comprador);

    let mut dados = vec![0u8; TradeState::LEN];
    trade_state(
        comprador,
        produtor,
        transportadora,
        bump,
        TradeState::STATUS_READY_FOR_PICKUP,
    )
    .pack(&mut dados)
    .unwrap();

    let resultado = avancar_entrega(&program_id, &comprador, &pda, &mut dados, 7);

    assert_eq!(resultado, Err(erro(FoodRescueError::InvalidAccount)));
}

#[test]
fn a_transicao_recusa_um_pda_que_nao_vem_das_seeds() {
    let program_id = key(9);
    let (comprador, produtor) = (key(1), key(2));
    let (_, bump) = trade_pda(&program_id, 42, &comprador);

    let mut dados = vec![0u8; TradeState::LEN];
    trade_state(comprador, produtor, key(3), bump, TradeState::STATUS_FUNDED)
        .pack(&mut dados)
        .unwrap();

    // PDA derivado de outro comprador, com o estado do comprador certo.
    let (pda_alheio, _) = trade_pda(&program_id, 42, &key(11));
    let resultado = avancar_entrega(&program_id, &produtor, &pda_alheio, &mut dados, 6);

    assert_eq!(resultado, Err(erro(FoodRescueError::InvalidPda)));
}
