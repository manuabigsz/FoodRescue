//! Exercita o fechamento pelo destinatário (buyer, inclusive a NGO em doações).
//! As CPIs são capturadas: estes testes verificam autorização, estados e as
//! transferências solicitadas, sem simular saldos ou rollback do runtime SPL.

use foodrescue::{
    error::FoodRescueError,
    state::{ProtocolConfig, TradeState},
    Processor,
};
use solana_program::{
    account_info::AccountInfo,
    instruction::Instruction,
    program_error::ProgramError,
    program_pack::Pack,
    program_stubs::{set_syscall_stubs, SyscallStubs},
    pubkey::Pubkey,
};
use spl_token::state::{Account as TokenAccount, AccountState, Mint};
use std::sync::{Arc, Mutex};

// Os syscall stubs são globais neste executável de teste.
static STUB_LOCK: Mutex<()> = Mutex::new(());

struct RestoreStubs(Option<Box<dyn SyscallStubs>>);

impl Drop for RestoreStubs {
    fn drop(&mut self) {
        set_syscall_stubs(self.0.take().unwrap());
    }
}

struct CaptureTransfers {
    program: Pubkey,
    transfers: Arc<Mutex<Vec<(Pubkey, u64)>>>,
}

impl SyscallStubs for CaptureTransfers {
    fn sol_invoke_signed(
        &self,
        instruction: &Instruction,
        accounts: &[AccountInfo],
        seeds: &[&[&[u8]]],
    ) -> Result<(), ProgramError> {
        assert_eq!(instruction.program_id, spl_token::id());
        assert_eq!(seeds.len(), 1);
        let signer = Pubkey::create_program_address(seeds[0], &self.program).unwrap();
        assert_eq!(instruction.accounts[3].pubkey, signer);
        let escrow = TokenAccount::unpack(&accounts[0].try_borrow_data()?).unwrap();
        assert_eq!(escrow.owner, signer);
        assert_eq!(instruction.accounts[0].pubkey, *accounts[0].key);
        assert_eq!(instruction.accounts[1].pubkey, escrow.mint);
        match spl_token::instruction::TokenInstruction::unpack(&instruction.data)? {
            spl_token::instruction::TokenInstruction::TransferChecked { amount, decimals } => {
                assert_eq!(decimals, 6);
                self.transfers
                    .lock()
                    .unwrap()
                    .push((instruction.accounts[2].pubkey, amount));
                Ok(())
            }
            _ => panic!("A liquidação deve usar transferChecked"),
        }
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

fn token_account(key: Pubkey, owner: Pubkey, mint: Pubkey, amount: u64) -> AccountInfo<'static> {
    let mut data = vec![0; TokenAccount::LEN];
    TokenAccount::pack(
        TokenAccount {
            mint,
            owner,
            amount,
            state: AccountState::Initialized,
            ..TokenAccount::default()
        },
        &mut data,
    )
    .unwrap();
    account(key, spl_token::id(), false, true, data)
}

fn recipient_closes_trade(product: u64, shipping: u64) {
    let _lock = STUB_LOCK.lock().unwrap();
    let program = Pubkey::new_unique();
    let recipient = Pubkey::new_unique();
    let producer = Pubkey::new_unique();
    let carrier = if shipping > 0 {
        Pubkey::new_unique()
    } else {
        Pubkey::default()
    };
    let mint = Pubkey::new_unique();
    let authority = Pubkey::new_unique();
    let treasury = Pubkey::new_unique();
    let trade_id = 42u64;
    let (trade, trade_bump) = Pubkey::find_program_address(
        &[
            Processor::TRADE_SEED,
            &trade_id.to_le_bytes(),
            recipient.as_ref(),
        ],
        &program,
    );
    let (vault, vault_bump) = Pubkey::find_program_address(
        &[
            Processor::VAULT_SEED,
            &trade_id.to_le_bytes(),
            recipient.as_ref(),
        ],
        &program,
    );
    let (protocol, bump) =
        Pubkey::find_program_address(&[Processor::PROTOCOL_SEED, authority.as_ref()], &program);
    let mut protocol_data = vec![0; ProtocolConfig::LEN];
    ProtocolConfig {
        version: ProtocolConfig::VERSION,
        bump,
        authority,
        treasury,
        mint,
    }
    .pack(&mut protocol_data)
    .unwrap();
    let mut state = TradeState {
        version: TradeState::VERSION,
        trade_bump,
        vault_bump,
        trade_id,
        buyer: recipient,
        producer,
        carrier,
        mint,
        vault,
        protocol_config: protocol,
        product_amount: product,
        shipping_amount: shipping,
        protocol_fee: Processor::protocol_fee(product),
        total_amount: product + shipping,
        expires_at: 2_000_000_000,
        status: TradeState::STATUS_FUNDED,
    };
    let mut trade_data = vec![0; TradeState::LEN];
    state.pack(&mut trade_data).unwrap();
    let mut mint_data = vec![0; Mint::LEN];
    Mint::pack(
        Mint {
            decimals: 6,
            is_initialized: true,
            ..Mint::default()
        },
        &mut mint_data,
    )
    .unwrap();
    let excess = 7;
    let mut accounts = vec![
        account(recipient, Pubkey::default(), true, false, vec![]),
        account(trade, program, false, true, trade_data),
        token_account(vault, trade, mint, state.total_amount + excess),
        token_account(Pubkey::new_unique(), recipient, mint, 0),
        account(protocol, program, false, false, protocol_data),
        token_account(Pubkey::new_unique(), producer, mint, 0),
        token_account(Pubkey::new_unique(), treasury, mint, 0),
        account(mint, spl_token::id(), false, false, mint_data),
        account(spl_token::id(), Pubkey::default(), false, false, vec![]),
    ];
    if shipping > 0 {
        accounts.push(token_account(Pubkey::new_unique(), carrier, mint, 0));
    }
    let transfers = Arc::new(Mutex::new(Vec::new()));
    let _restore = RestoreStubs(Some(set_syscall_stubs(Box::new(CaptureTransfers {
        program,
        transfers: transfers.clone(),
    }))));

    // Só estados entregues podem ser liquidados, mesmo com o destinatário assinando.
    for status in [
        TradeState::STATUS_INITIALIZED,
        TradeState::STATUS_FUNDED,
        TradeState::STATUS_READY_FOR_PICKUP,
        TradeState::STATUS_IN_TRANSIT,
        TradeState::STATUS_CANCELLED,
        TradeState::STATUS_SETTLED,
        255,
    ] {
        state.status = status;
        state.pack(&mut accounts[1].data.borrow_mut()).unwrap();
        assert_eq!(
            Processor::process(&program, &accounts, &[3]),
            Err(ProgramError::from(FoodRescueError::InvalidState))
        );
        assert_eq!(
            TradeState::unpack(&accounts[1].data.borrow()).unwrap(),
            state
        );
        assert!(transfers.lock().unwrap().is_empty());
    }

    // Liberação e coleta avançam a mesma conta, sem forçar estados intermediários.
    state.status = TradeState::STATUS_FUNDED;
    state.pack(&mut accounts[1].data.borrow_mut()).unwrap();
    let collector = if shipping > 0 { carrier } else { recipient };
    for (tag, actor) in [(6, producer), (7, collector)] {
        Processor::process(
            &program,
            &[
                account(actor, Pubkey::default(), true, false, vec![]),
                accounts[1].clone(),
            ],
            &[tag],
        )
        .unwrap();
    }
    state.status = TradeState::STATUS_IN_TRANSIT;
    assert_eq!(
        TradeState::unpack(&accounts[1].data.borrow()).unwrap(),
        state
    );

    // Nem o produtor nem a transportadora podem confirmar recebimento ou liquidar.
    let mut outsiders = vec![producer, Pubkey::new_unique()];
    if shipping > 0 {
        outsiders.push(carrier);
    }
    for actor in &outsiders {
        assert_eq!(
            Processor::process(
                &program,
                &[
                    account(*actor, Pubkey::default(), true, false, vec![]),
                    accounts[1].clone(),
                ],
                &[8]
            ),
            Err(ProgramError::from(FoodRescueError::InvalidAccount))
        );
        assert_eq!(
            TradeState::unpack(&accounts[1].data.borrow()).unwrap(),
            state
        );
    }
    let mut delivery = vec![accounts[0].clone(), accounts[1].clone()];
    delivery[0].is_signer = false;
    assert_eq!(
        Processor::process(&program, &delivery, &[8]),
        Err(ProgramError::from(FoodRescueError::InvalidAccount))
    );
    assert_eq!(
        TradeState::unpack(&accounts[1].data.borrow()).unwrap(),
        state
    );
    delivery[0].is_signer = true;
    Processor::process(&program, &delivery, &[8]).unwrap();
    state.status = TradeState::STATUS_DELIVERED;
    assert_eq!(
        TradeState::unpack(&accounts[1].data.borrow()).unwrap(),
        state
    );
    assert!(
        transfers.lock().unwrap().is_empty(),
        "Confirmar entrega não liquida o escrow"
    );

    for actor in outsiders {
        let mut unauthorized = accounts.clone();
        unauthorized[0] = account(actor, Pubkey::default(), true, false, vec![]);
        assert_eq!(
            Processor::process(&program, &unauthorized, &[3]),
            Err(ProgramError::from(FoodRescueError::InvalidState))
        );
        assert_eq!(
            TradeState::unpack(&accounts[1].data.borrow()).unwrap(),
            state
        );
        assert!(transfers.lock().unwrap().is_empty());
    }
    accounts[0].is_signer = false;
    assert_eq!(
        Processor::process(&program, &accounts, &[3]),
        Err(ProgramError::from(FoodRescueError::InvalidAccount))
    );
    assert_eq!(
        TradeState::unpack(&accounts[1].data.borrow()).unwrap(),
        state
    );
    assert!(transfers.lock().unwrap().is_empty());
    accounts[0].is_signer = true;

    Processor::process(&program, &accounts, &[3]).unwrap();
    state.status = TradeState::STATUS_SETTLED;
    assert_eq!(
        TradeState::unpack(&accounts[1].data.borrow()).unwrap(),
        state
    );
    let mut expected = Vec::new();
    if product > 0 {
        expected.push((*accounts[5].key, product - state.protocol_fee));
        expected.push((*accounts[6].key, state.protocol_fee));
    }
    if shipping > 0 {
        expected.push((*accounts[9].key, shipping));
    }
    expected.push((*accounts[3].key, excess));
    assert_eq!(*transfers.lock().unwrap(), expected);

    // O mesmo destinatário não pode liquidar outra vez nem repetir a entrega.
    for (instruction_accounts, tag) in [(&accounts, 3), (&delivery, 8)] {
        assert_eq!(
            Processor::process(&program, instruction_accounts, &[tag]),
            Err(ProgramError::from(FoodRescueError::InvalidState))
        );
        assert_eq!(
            TradeState::unpack(&accounts[1].data.borrow()).unwrap(),
            state
        );
        assert_eq!(*transfers.lock().unwrap(), expected);
    }
}

#[test]
fn comprador_confirma_entrega_e_liquida_compra_com_transportadora() {
    recipient_closes_trade(100, 20);
}

#[test]
fn comprador_coleta_confirma_entrega_e_liquida_compra_com_transporte_proprio() {
    recipient_closes_trade(100, 0);
}

#[test]
fn ong_confirma_entrega_e_liquida_apenas_o_frete_da_doacao() {
    // No contrato a NGO ocupa state.buyer; o Proof of Rescue é uma etapa separada.
    recipient_closes_trade(0, 20);
}
