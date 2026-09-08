use foodrescue::{
    error::FoodRescueError,
    instruction::FoodRescueInstruction,
    state::{ProtocolConfig, TradeState},
    Processor,
};
use solana_program::{account_info::AccountInfo, program_error::ProgramError, pubkey::Pubkey};

#[test]
fn instruction_decoding_accepts_only_exact_lengths_for_all_tags() {
    for (tag, len) in [
        (0, 105),
        (1, 1),
        (2, 33),
        (3, 1),
        (4, 1),
        (5, 73),
        (6, 1),
        (7, 1),
        (8, 1),
    ] {
        let mut valid = vec![0; len];
        valid[0] = tag;
        assert!(FoodRescueInstruction::unpack(&valid).is_ok());
        for size in 0..110 {
            if size == len {
                continue;
            }
            let mut malformed = vec![0; size];
            if size > 0 {
                malformed[0] = tag;
            }
            assert!(
                FoodRescueInstruction::unpack(&malformed).is_err(),
                "tag={tag}, size={size}"
            );
        }
    }
    assert!(FoodRescueInstruction::unpack(&[255]).is_err());
}

#[test]
fn delivery_transitions_require_the_expected_actor_and_order() {
    let program = Pubkey::new_unique();
    let mut state = state(TradeState::STATUS_FUNDED);
    let id = state.trade_id.to_le_bytes();
    let (trade, bump) =
        Pubkey::find_program_address(&[b"foodrescue_trade", &id, state.buyer.as_ref()], &program);
    state.trade_bump = bump;

    for (tag, actor, from, to) in [
        (
            6,
            state.producer,
            TradeState::STATUS_FUNDED,
            TradeState::STATUS_READY_FOR_PICKUP,
        ),
        (
            7,
            state.buyer,
            TradeState::STATUS_READY_FOR_PICKUP,
            TradeState::STATUS_IN_TRANSIT,
        ),
        (
            8,
            state.buyer,
            TradeState::STATUS_IN_TRANSIT,
            TradeState::STATUS_DELIVERED,
        ),
    ] {
        state.status = from;
        let mut raw = vec![0; TradeState::LEN];
        state.pack(&mut raw).unwrap();
        let accounts = vec![
            account(actor, Pubkey::default(), true, false, vec![]),
            account(trade, program, false, true, raw),
        ];
        assert_eq!(Processor::process(&program, &accounts, &[tag]), Ok(()));
        assert_eq!(
            TradeState::unpack(&accounts[1].data.borrow())
                .unwrap()
                .status,
            to
        );
    }

    state.status = TradeState::STATUS_FUNDED;
    let mut raw = vec![0; TradeState::LEN];
    state.pack(&mut raw).unwrap();
    let wrong_actor = account(state.buyer, Pubkey::default(), true, false, vec![]);
    let accounts = vec![wrong_actor, account(trade, program, false, true, raw)];
    assert_eq!(
        Processor::process(&program, &accounts, &[6]),
        Err(ProgramError::from(FoodRescueError::InvalidAccount))
    );
}

#[test]
fn financial_fee_never_overflows_and_rounds_down_in_base_units() {
    assert_eq!(Processor::protocol_fee(0), 0);
    assert_eq!(Processor::protocol_fee(49), 0);
    assert_eq!(Processor::protocol_fee(50), 1);
    assert_eq!(Processor::protocol_fee(100_123_456), 2_002_469);
    assert_eq!(Processor::protocol_fee(u64::MAX), 368_934_881_474_191_032);
    for product in [0, 1, 49, 50, 100_123_456, u64::MAX] {
        let fee = Processor::protocol_fee(product);
        assert_eq!(
            product.checked_sub(fee).unwrap().checked_add(fee),
            Some(product)
        );
    }
}

fn account(
    key: Pubkey,
    owner: Pubkey,
    signer: bool,
    writable: bool,
    data: Vec<u8>,
) -> AccountInfo<'static> {
    AccountInfo::new(
        Box::leak(Box::new(key)),
        signer,
        writable,
        Box::leak(Box::new(1)),
        Box::leak(data.into_boxed_slice()),
        Box::leak(Box::new(owner)),
        false,
        0,
    )
}

fn state(status: u8) -> TradeState {
    TradeState {
        version: 2,
        trade_bump: 254,
        vault_bump: 253,
        trade_id: 42,
        buyer: Pubkey::new_from_array([1; 32]),
        producer: Pubkey::new_from_array([2; 32]),
        carrier: Pubkey::default(),
        mint: Pubkey::new_from_array([4; 32]),
        vault: Pubkey::new_from_array([5; 32]),
        protocol_config: Pubkey::new_from_array([6; 32]),
        product_amount: 100,
        shipping_amount: 0,
        protocol_fee: 2,
        total_amount: 100,
        expires_at: 2_000_000_000,
        status,
    }
}

#[test]
fn funded_refunds_cannot_bypass_the_two_signer_requirement() {
    let program = Pubkey::new_unique();
    for mask in 0..3 {
        let state = state(TradeState::STATUS_FUNDED);
        let mut data = vec![0; TradeState::LEN];
        state.pack(&mut data).unwrap();
        let accounts = vec![
            account(state.buyer, Pubkey::default(), mask & 1 != 0, false, vec![]),
            account(
                state.producer,
                Pubkey::default(),
                mask & 2 != 0,
                false,
                vec![],
            ),
            account(Pubkey::new_unique(), program, false, true, data),
            account(state.vault, spl_token::id(), false, true, vec![]),
            account(Pubkey::new_unique(), spl_token::id(), false, true, vec![]),
            account(state.mint, spl_token::id(), false, false, vec![]),
            account(spl_token::id(), Pubkey::default(), false, false, vec![]),
        ];
        assert_eq!(
            Processor::process(&program, &accounts, &[4]),
            Err(ProgramError::from(FoodRescueError::InvalidAccount))
        );
    }
}

#[test]
fn cancellation_rejects_terminal_and_unknown_states_even_with_both_signers() {
    let program = Pubkey::new_unique();
    for status in [2, 3, 4, 255] {
        let state = state(status);
        let mut raw = vec![0; TradeState::LEN];
        state.pack(&mut raw).unwrap();
        let accounts = vec![
            account(state.buyer, Pubkey::default(), true, false, vec![]),
            account(state.producer, Pubkey::default(), true, false, vec![]),
            account(Pubkey::new_unique(), program, false, true, raw),
            account(state.vault, spl_token::id(), false, true, vec![]),
            account(Pubkey::new_unique(), spl_token::id(), false, true, vec![]),
            account(state.mint, spl_token::id(), false, false, vec![]),
            account(spl_token::id(), Pubkey::default(), false, false, vec![]),
        ];
        assert_eq!(
            Processor::process(&program, &accounts, &[4]),
            Err(ProgramError::from(FoodRescueError::InvalidState))
        );
    }
}

#[test]
fn rescue_proof_rejects_missing_ngo_signature_same_actors_and_empty_hash() {
    let program = Pubkey::new_unique();
    let authority = Pubkey::new_unique();
    let (protocol_key, protocol_bump) =
        Pubkey::find_program_address(&[b"foodrescue_protocol", authority.as_ref()], &program);
    let mut protocol_data = vec![0; ProtocolConfig::LEN];
    ProtocolConfig {
        version: ProtocolConfig::VERSION,
        bump: protocol_bump,
        authority,
        treasury: Pubkey::new_unique(),
        mint: Pubkey::new_unique(),
    }
    .pack(&mut protocol_data)
    .unwrap();

    let ngo = Pubkey::new_unique();
    let producer = Pubkey::new_unique();

    // A ordem das contas acompanha o programa: ngo, producer, ProtocolConfig,
    // Rescue Proof PDA e System Program. A authority deixou de ser signatária.
    let contas = |ngo_key: Pubkey, ngo_assina: bool, producer_key: Pubkey, producer_assina: bool| {
        vec![
            account(ngo_key, Pubkey::default(), ngo_assina, true, vec![]),
            account(
                producer_key,
                Pubkey::default(),
                producer_assina,
                false,
                vec![],
            ),
            account(protocol_key, program, false, false, protocol_data.clone()),
            account(Pubkey::new_unique(), program, false, true, vec![]),
            account(
                solana_sdk_ids::system_program::id(),
                Pubkey::default(),
                false,
                false,
                vec![],
            ),
        ]
    };
    let mut instruction = vec![5];
    instruction.extend_from_slice(&[1; 72]);

    // Sem a assinatura da NGO nada acontece, mesmo com o produtor assinando.
    for producer_assina in [false, true] {
        assert_eq!(
            Processor::process(&program, &contas(ngo, false, producer, producer_assina), &instruction),
            Err(ProgramError::from(FoodRescueError::InvalidAccount))
        );
    }

    // NGO e produtor não podem ser a mesma carteira: a atestação perde o sentido.
    assert_eq!(
        Processor::process(&program, &contas(ngo, true, ngo, false), &instruction),
        Err(ProgramError::from(FoodRescueError::InvalidAccount))
    );

    // Hash de metadados zerado é recusado.
    let mut sem_hash = instruction.clone();
    sem_hash[41..73].fill(0);
    assert_eq!(
        Processor::process(&program, &contas(ngo, true, producer, false), &sem_hash),
        Err(ProgramError::from(FoodRescueError::InvalidAccount))
    );

    // Com a NGO assinando e o produtor NÃO assinando, a execução passa de todos
    // os guards de conta e só para na derivação do PDA — que aqui é aleatório de
    // propósito. É o que prova que a assinatura do produtor não é exigida nesta
    // instrução: ela vem depois, no `confirm_rescue_proof`.
    assert_eq!(
        Processor::process(&program, &contas(ngo, true, producer, false), &instruction),
        Err(ProgramError::from(FoodRescueError::InvalidPda))
    );
}

#[test]
fn binary_state_sizes_and_versions_are_strict() {
    let state = state(1);
    let mut raw = vec![0; 244];
    state.pack(&mut raw).unwrap();
    assert_eq!(TradeState::unpack(&raw).unwrap(), state);
    assert_eq!(&raw[3..11], &42u64.to_le_bytes());
    assert_eq!(&raw[203..211], &100u64.to_le_bytes());
    assert_eq!(&raw[235..243], &2_000_000_000i64.to_le_bytes());
    assert_eq!(raw[243], 1);
    raw[0] = 1;
    assert!(TradeState::unpack(&raw).is_err());
    assert!(TradeState::unpack(&raw[..243]).is_err());
    assert!(ProtocolConfig::unpack(&[0; 98]).is_err());
    assert!(ProtocolConfig::unpack(&[1; 97]).is_err());
}

#[test]
fn cancellation_transfers_the_entire_vault_including_unsolicited_tokens() {
    use solana_program::{
        instruction::Instruction,
        program_pack::Pack,
        program_stubs::{set_syscall_stubs, SyscallStubs},
    };
    use spl_token::state::{Account as TokenAccount, AccountState, Mint};
    use std::sync::{
        atomic::{AtomicU64, Ordering},
        Arc,
    };

    struct CaptureTransfer(Arc<AtomicU64>);
    impl SyscallStubs for CaptureTransfer {
        fn sol_invoke_signed(
            &self,
            instruction: &Instruction,
            _accounts: &[AccountInfo],
            _seeds: &[&[&[u8]]],
        ) -> Result<(), ProgramError> {
            assert_eq!(instruction.program_id, spl_token::id());
            match spl_token::instruction::TokenInstruction::unpack(&instruction.data)? {
                spl_token::instruction::TokenInstruction::TransferChecked { amount, decimals } => {
                    assert_eq!(decimals, 6);
                    self.0.store(amount, Ordering::SeqCst);
                    Ok(())
                }
                _ => panic!("Expected refund transfer"),
            }
        }
    }
    let program = Pubkey::new_unique();
    let mut state = state(TradeState::STATUS_FUNDED);
    let id = state.trade_id.to_le_bytes();
    let (trade, trade_bump) =
        Pubkey::find_program_address(&[b"foodrescue_trade", &id, state.buyer.as_ref()], &program);
    let (vault, vault_bump) =
        Pubkey::find_program_address(&[b"foodrescue_vault", &id, state.buyer.as_ref()], &program);
    state.trade_bump = trade_bump;
    state.vault_bump = vault_bump;
    state.vault = vault;
    let mut raw = vec![0; TradeState::LEN];
    state.pack(&mut raw).unwrap();
    let mut escrow = vec![0; TokenAccount::LEN];
    TokenAccount::pack(
        TokenAccount {
            mint: state.mint,
            owner: trade,
            amount: 107,
            state: AccountState::Initialized,
            ..TokenAccount::default()
        },
        &mut escrow,
    )
    .unwrap();
    let mut destination = vec![0; TokenAccount::LEN];
    TokenAccount::pack(
        TokenAccount {
            mint: state.mint,
            owner: state.buyer,
            state: AccountState::Initialized,
            ..TokenAccount::default()
        },
        &mut destination,
    )
    .unwrap();
    let mut mint = vec![0; Mint::LEN];
    Mint::pack(
        Mint {
            decimals: 6,
            is_initialized: true,
            ..Mint::default()
        },
        &mut mint,
    )
    .unwrap();
    let accounts = vec![
        account(state.buyer, Pubkey::default(), true, false, vec![]),
        account(state.producer, Pubkey::default(), true, false, vec![]),
        account(trade, program, false, true, raw),
        account(vault, spl_token::id(), false, true, escrow),
        account(
            Pubkey::new_unique(),
            spl_token::id(),
            false,
            true,
            destination,
        ),
        account(state.mint, spl_token::id(), false, false, mint),
        account(spl_token::id(), Pubkey::default(), false, false, vec![]),
    ];
    let amount = Arc::new(AtomicU64::new(0));
    for index in [2, 3, 4, 5] {
        let mut invalid = accounts.clone();
        invalid[index].owner = Box::leak(Box::new(Pubkey::new_unique()));
        assert!(Processor::process(&program, &invalid, &[4]).is_err());
    }
    for index in [2, 3, 4] {
        let mut invalid = accounts.clone();
        invalid[index].is_writable = false;
        assert!(Processor::process(&program, &invalid, &[4]).is_err());
    }
    let original = set_syscall_stubs(Box::new(CaptureTransfer(amount.clone())));
    let result = Processor::process(&program, &accounts, &[4]);
    set_syscall_stubs(original);
    assert_eq!(result, Ok(()));
    assert_eq!(amount.load(Ordering::SeqCst), 107);
    assert_eq!(
        TradeState::unpack(&accounts[2].data.borrow())
            .unwrap()
            .status,
        TradeState::STATUS_CANCELLED
    );

    // Initialized escrows allow either participant and refund dust as well.
    for signer in [0, 1] {
        let mut initialized = accounts.clone();
        initialized[0].is_signer = signer == 0;
        initialized[1].is_signer = signer == 1;
        state.status = TradeState::STATUS_INITIALIZED;
        state.pack(&mut initialized[2].data.borrow_mut()).unwrap();
        amount.store(0, Ordering::SeqCst);
        let original = set_syscall_stubs(Box::new(CaptureTransfer(amount.clone())));
        let result = Processor::process(&program, &initialized, &[4]);
        set_syscall_stubs(original);
        assert_eq!(result, Ok(()));
        assert_eq!(amount.load(Ordering::SeqCst), 107);
    }
}
