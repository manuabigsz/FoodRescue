use crate::{
    error::FoodRescueError,
    instruction::FoodRescueInstruction,
    state::{ProtocolConfig, RescueProofState, TradeState},
};
use solana_program::{
    account_info::{next_account_info, AccountInfo},
    clock::Clock,
    entrypoint::ProgramResult,
    program::{invoke, invoke_signed},
    program_pack::Pack,
    pubkey::Pubkey,
    rent::Rent,
    sysvar::Sysvar,
};
use solana_sdk_ids::system_program;
use solana_system_interface::instruction as system_instruction;
use spl_token::{
    instruction as token_instruction,
    state::{Account as TokenAccount, Mint},
};

pub struct Processor;

impl Processor {
    pub const TRADE_SEED: &'static [u8] = b"foodrescue_trade";
    pub const VAULT_SEED: &'static [u8] = b"foodrescue_vault";
    pub const PROTOCOL_SEED: &'static [u8] = b"foodrescue_protocol";
    pub const RESCUE_SEED: &'static [u8] = b"foodrescue_rescue";
    pub const PROTOCOL_FEE_BPS: u64 = 200;

    pub fn protocol_fee(product_amount: u64) -> u64 {
        ((product_amount as u128 * Self::PROTOCOL_FEE_BPS as u128) / 10_000) as u64
    }

    pub fn process(program_id: &Pubkey, accounts: &[AccountInfo], data: &[u8]) -> ProgramResult {
        match FoodRescueInstruction::unpack(data)? {
            FoodRescueInstruction::InitializeTrade {
                trade_id,
                product_amount,
                shipping_amount,
                protocol_fee,
                expires_at,
                producer,
                carrier,
            } => Self::initialize_trade(
                program_id,
                accounts,
                trade_id,
                product_amount,
                shipping_amount,
                protocol_fee,
                expires_at,
                producer,
                carrier,
            ),
            FoodRescueInstruction::FundTrade => Self::fund_trade(program_id, accounts),
            FoodRescueInstruction::InitializeProtocol { treasury } => {
                Self::initialize_protocol(program_id, accounts, treasury)
            }
            FoodRescueInstruction::SettleTrade => Self::settle_trade(program_id, accounts),
            FoodRescueInstruction::CancelTrade => Self::cancel_trade(program_id, accounts),
            FoodRescueInstruction::MarkReadyForPickup => Self::advance_delivery(
                program_id,
                accounts,
                TradeState::STATUS_FUNDED,
                TradeState::STATUS_READY_FOR_PICKUP,
                false,
            ),
            FoodRescueInstruction::ConfirmPickup => Self::advance_delivery(
                program_id,
                accounts,
                TradeState::STATUS_READY_FOR_PICKUP,
                TradeState::STATUS_IN_TRANSIT,
                true,
            ),
            FoodRescueInstruction::MarkDelivered => Self::advance_delivery(
                program_id,
                accounts,
                TradeState::STATUS_IN_TRANSIT,
                TradeState::STATUS_DELIVERED,
                true,
            ),
            FoodRescueInstruction::CreateRescueProof {
                trade_id,
                carrier,
                metadata_hash,
            } => Self::create_rescue_proof(program_id, accounts, trade_id, carrier, metadata_hash),
        }
    }

    fn initialize_protocol(
        program_id: &Pubkey,
        accounts: &[AccountInfo],
        treasury: Pubkey,
    ) -> ProgramResult {
        let accounts_iter = &mut accounts.iter();
        let authority = next_account_info(accounts_iter)?;
        let protocol_config = next_account_info(accounts_iter)?;
        let mint = next_account_info(accounts_iter)?;
        let system = next_account_info(accounts_iter)?;

        if !authority.is_signer || !authority.is_writable || !protocol_config.is_writable {
            return Err(FoodRescueError::InvalidAccount.into());
        }
        if system.key != &system_program::id() || mint.owner != &spl_token::id() {
            return Err(FoodRescueError::InvalidAccount.into());
        }

        let (expected_config, bump) = Pubkey::find_program_address(
            &[Self::PROTOCOL_SEED, authority.key.as_ref()],
            program_id,
        );
        if protocol_config.key != &expected_config {
            return Err(FoodRescueError::InvalidPda.into());
        }

        Mint::unpack(&mint.try_borrow_data()?)?;
        let rent = Rent::get()?;
        invoke_signed(
            &system_instruction::create_account(
                authority.key,
                protocol_config.key,
                rent.minimum_balance(ProtocolConfig::LEN),
                ProtocolConfig::LEN as u64,
                program_id,
            ),
            &[authority.clone(), protocol_config.clone(), system.clone()],
            &[&[Self::PROTOCOL_SEED, authority.key.as_ref(), &[bump]]],
        )?;

        ProtocolConfig {
            version: ProtocolConfig::VERSION,
            bump,
            authority: *authority.key,
            treasury,
            mint: *mint.key,
        }
        .pack(&mut protocol_config.try_borrow_mut_data()?)?;

        Ok(())
    }

    #[allow(clippy::too_many_arguments)]
    fn initialize_trade(
        program_id: &Pubkey,
        accounts: &[AccountInfo],
        trade_id: u64,
        product_amount: u64,
        shipping_amount: u64,
        protocol_fee: u64,
        expires_at: i64,
        producer: Pubkey,
        carrier: Pubkey,
    ) -> ProgramResult {
        let accounts_iter = &mut accounts.iter();
        let buyer = next_account_info(accounts_iter)?;
        let trade_pda = next_account_info(accounts_iter)?;
        let vault = next_account_info(accounts_iter)?;
        let buyer_token = next_account_info(accounts_iter)?;
        let protocol_config = next_account_info(accounts_iter)?;
        let mint = next_account_info(accounts_iter)?;
        let system = next_account_info(accounts_iter)?;
        let token_program = next_account_info(accounts_iter)?;

        if !buyer.is_signer
            || !buyer.is_writable
            || !trade_pda.is_writable
            || !vault.is_writable
            || !buyer_token.is_writable
        {
            return Err(FoodRescueError::InvalidAccount.into());
        }
        if system.key != &system_program::id() || token_program.key != &spl_token::id() {
            return Err(FoodRescueError::InvalidAccount.into());
        }
        if mint.owner != token_program.key || protocol_config.owner != program_id {
            return Err(FoodRescueError::InvalidTokenAccount.into());
        }

        let protocol = ProtocolConfig::unpack(&protocol_config.try_borrow_data()?)?;
        let expected_protocol = Pubkey::create_program_address(
            &[
                Self::PROTOCOL_SEED,
                protocol.authority.as_ref(),
                &[protocol.bump],
            ],
            program_id,
        )
        .map_err(|_| FoodRescueError::InvalidPda)?;
        if expected_protocol != *protocol_config.key || protocol.mint != *mint.key {
            return Err(FoodRescueError::InvalidPda.into());
        }

        let trade_id_bytes = trade_id.to_le_bytes();
        let (expected_trade, trade_bump) =
            Pubkey::find_program_address(&[Self::TRADE_SEED, &trade_id_bytes], program_id);
        let (expected_vault, vault_bump) =
            Pubkey::find_program_address(&[Self::VAULT_SEED, &trade_id_bytes], program_id);
        if trade_pda.key != &expected_trade || vault.key != &expected_vault {
            return Err(FoodRescueError::InvalidPda.into());
        }

        let expected_fee = Self::protocol_fee(product_amount);
        if producer == Pubkey::default()
            || producer == *buyer.key
            || (shipping_amount > 0 && carrier == Pubkey::default())
        {
            return Err(FoodRescueError::InvalidAccount.into());
        }
        if protocol_fee != expected_fee {
            return Err(FoodRescueError::InvalidFee.into());
        }
        let total_amount = product_amount
            .checked_add(shipping_amount)
            .ok_or(FoodRescueError::ArithmeticOverflow)?;
        if total_amount == 0 || expires_at <= Clock::get()?.unix_timestamp {
            return Err(FoodRescueError::PaymentExpired.into());
        }

        let rent = Rent::get()?;
        invoke_signed(
            &system_instruction::create_account(
                buyer.key,
                trade_pda.key,
                rent.minimum_balance(TradeState::LEN),
                TradeState::LEN as u64,
                program_id,
            ),
            &[buyer.clone(), trade_pda.clone(), system.clone()],
            &[&[Self::TRADE_SEED, &trade_id_bytes, &[trade_bump]]],
        )?;

        invoke_signed(
            &system_instruction::create_account(
                buyer.key,
                vault.key,
                rent.minimum_balance(TokenAccount::LEN),
                TokenAccount::LEN as u64,
                token_program.key,
            ),
            &[buyer.clone(), vault.clone(), system.clone()],
            &[&[Self::VAULT_SEED, &trade_id_bytes, &[vault_bump]]],
        )?;

        invoke(
            &token_instruction::initialize_account3(
                token_program.key,
                vault.key,
                mint.key,
                trade_pda.key,
            )?,
            &[vault.clone(), mint.clone(), token_program.clone()],
        )?;

        TradeState {
            version: TradeState::VERSION,
            trade_bump,
            vault_bump,
            trade_id,
            buyer: *buyer.key,
            producer,
            carrier,
            mint: *mint.key,
            vault: *vault.key,
            protocol_config: *protocol_config.key,
            product_amount,
            shipping_amount,
            protocol_fee,
            total_amount,
            expires_at,
            status: TradeState::STATUS_INITIALIZED,
        }
        .pack(&mut trade_pda.try_borrow_mut_data()?)?;
        Ok(())
    }

    fn fund_trade(program_id: &Pubkey, accounts: &[AccountInfo]) -> ProgramResult {
        let accounts_iter = &mut accounts.iter();
        let buyer = next_account_info(accounts_iter)?;
        let trade_pda = next_account_info(accounts_iter)?;
        let buyer_token = next_account_info(accounts_iter)?;
        let vault = next_account_info(accounts_iter)?;
        let mint = next_account_info(accounts_iter)?;
        let token_program = next_account_info(accounts_iter)?;

        if !buyer.is_signer
            || !trade_pda.is_writable
            || !buyer_token.is_writable
            || !vault.is_writable
        {
            return Err(FoodRescueError::InvalidAccount.into());
        }
        if trade_pda.owner != program_id
            || token_program.key != &spl_token::id()
            || mint.owner != token_program.key
        {
            return Err(FoodRescueError::InvalidAccount.into());
        }

        let mut state = TradeState::unpack(&trade_pda.try_borrow_data()?)?;
        if state.status != TradeState::STATUS_INITIALIZED || state.buyer != *buyer.key {
            return Err(FoodRescueError::InvalidState.into());
        }
        if state.mint != *mint.key || state.vault != *vault.key {
            return Err(FoodRescueError::InvalidTokenAccount.into());
        }
        if Clock::get()?.unix_timestamp >= state.expires_at {
            return Err(FoodRescueError::PaymentExpired.into());
        }

        let trade_id_bytes = state.trade_id.to_le_bytes();
        let expected_trade = Pubkey::create_program_address(
            &[Self::TRADE_SEED, &trade_id_bytes, &[state.trade_bump]],
            program_id,
        )
        .map_err(|_| FoodRescueError::InvalidPda)?;
        let expected_vault = Pubkey::create_program_address(
            &[Self::VAULT_SEED, &trade_id_bytes, &[state.vault_bump]],
            program_id,
        )
        .map_err(|_| FoodRescueError::InvalidPda)?;
        if expected_trade != *trade_pda.key || expected_vault != *vault.key {
            return Err(FoodRescueError::InvalidPda.into());
        }

        if buyer_token.owner != token_program.key || vault.owner != token_program.key {
            return Err(FoodRescueError::InvalidTokenAccount.into());
        }
        let source = TokenAccount::unpack(&buyer_token.try_borrow_data()?)?;
        let escrow = TokenAccount::unpack(&vault.try_borrow_data()?)?;
        let mint_state = Mint::unpack(&mint.try_borrow_data()?)?;
        if source.owner != *buyer.key || source.mint != state.mint {
            return Err(FoodRescueError::InvalidTokenAccount.into());
        }
        if escrow.owner != *trade_pda.key || escrow.mint != state.mint {
            return Err(FoodRescueError::InvalidTokenAccount.into());
        }

        invoke(
            &token_instruction::transfer_checked(
                token_program.key,
                buyer_token.key,
                mint.key,
                vault.key,
                buyer.key,
                &[],
                state.total_amount,
                mint_state.decimals,
            )?,
            &[
                buyer_token.clone(),
                mint.clone(),
                vault.clone(),
                buyer.clone(),
                token_program.clone(),
            ],
        )?;

        state.status = TradeState::STATUS_FUNDED;
        state.pack(&mut trade_pda.try_borrow_mut_data()?)?;
        Ok(())
    }

    fn settle_trade(program_id: &Pubkey, accounts: &[AccountInfo]) -> ProgramResult {
        let accounts_iter = &mut accounts.iter();
        let buyer = next_account_info(accounts_iter)?;
        let trade_pda = next_account_info(accounts_iter)?;
        let vault = next_account_info(accounts_iter)?;
        let buyer_token = next_account_info(accounts_iter)?;
        let protocol_config = next_account_info(accounts_iter)?;
        let producer_token = next_account_info(accounts_iter)?;
        let treasury_token = next_account_info(accounts_iter)?;
        let mint = next_account_info(accounts_iter)?;
        let token_program = next_account_info(accounts_iter)?;

        if !buyer.is_signer
            || !trade_pda.is_writable
            || !vault.is_writable
            || !producer_token.is_writable
            || !treasury_token.is_writable
        {
            return Err(FoodRescueError::InvalidAccount.into());
        }
        if trade_pda.owner != program_id
            || protocol_config.owner != program_id
            || token_program.key != &spl_token::id()
            || mint.owner != token_program.key
        {
            return Err(FoodRescueError::InvalidAccount.into());
        }

        let mut state = TradeState::unpack(&trade_pda.try_borrow_data()?)?;
        if state.status != TradeState::STATUS_DELIVERED || state.buyer != *buyer.key {
            return Err(FoodRescueError::InvalidState.into());
        }
        if state.mint != *mint.key
            || state.vault != *vault.key
            || state.protocol_config != *protocol_config.key
        {
            return Err(FoodRescueError::InvalidTokenAccount.into());
        }

        let protocol = ProtocolConfig::unpack(&protocol_config.try_borrow_data()?)?;
        let expected_protocol = Pubkey::create_program_address(
            &[
                Self::PROTOCOL_SEED,
                protocol.authority.as_ref(),
                &[protocol.bump],
            ],
            program_id,
        )
        .map_err(|_| FoodRescueError::InvalidPda)?;
        if expected_protocol != *protocol_config.key || protocol.mint != state.mint {
            return Err(FoodRescueError::InvalidPda.into());
        }

        let trade_id_bytes = state.trade_id.to_le_bytes();
        let expected_trade = Pubkey::create_program_address(
            &[Self::TRADE_SEED, &trade_id_bytes, &[state.trade_bump]],
            program_id,
        )
        .map_err(|_| FoodRescueError::InvalidPda)?;
        let expected_vault = Pubkey::create_program_address(
            &[Self::VAULT_SEED, &trade_id_bytes, &[state.vault_bump]],
            program_id,
        )
        .map_err(|_| FoodRescueError::InvalidPda)?;
        if expected_trade != *trade_pda.key || expected_vault != *vault.key {
            return Err(FoodRescueError::InvalidPda.into());
        }

        if vault.owner != token_program.key
            || producer_token.owner != token_program.key
            || treasury_token.owner != token_program.key
            || buyer_token.owner != token_program.key
        {
            return Err(FoodRescueError::InvalidTokenAccount.into());
        }
        let escrow = TokenAccount::unpack(&vault.try_borrow_data()?)?;
        let producer_destination = TokenAccount::unpack(&producer_token.try_borrow_data()?)?;
        let treasury_destination = TokenAccount::unpack(&treasury_token.try_borrow_data()?)?;
        let buyer_destination = TokenAccount::unpack(&buyer_token.try_borrow_data()?)?;
        let mint_state = Mint::unpack(&mint.try_borrow_data()?)?;

        if escrow.owner != *trade_pda.key
            || escrow.mint != state.mint
            || escrow.amount < state.total_amount
        {
            return Err(FoodRescueError::InvalidTokenAccount.into());
        }
        if producer_destination.owner != state.producer || producer_destination.mint != state.mint {
            return Err(FoodRescueError::InvalidTokenAccount.into());
        }
        if treasury_destination.owner != protocol.treasury
            || treasury_destination.mint != state.mint
        {
            return Err(FoodRescueError::InvalidTokenAccount.into());
        }
        if buyer_destination.owner != state.buyer || buyer_destination.mint != state.mint {
            return Err(FoodRescueError::InvalidTokenAccount.into());
        }

        let producer_amount = state
            .product_amount
            .checked_sub(state.protocol_fee)
            .ok_or(FoodRescueError::ArithmeticOverflow)?;
        let trade_bump_seed = [state.trade_bump];
        let signer_seeds: &[&[u8]] = &[Self::TRADE_SEED, &trade_id_bytes, &trade_bump_seed];

        if producer_amount > 0 {
            invoke_signed(
                &token_instruction::transfer_checked(
                    token_program.key,
                    vault.key,
                    mint.key,
                    producer_token.key,
                    trade_pda.key,
                    &[],
                    producer_amount,
                    mint_state.decimals,
                )?,
                &[
                    vault.clone(),
                    mint.clone(),
                    producer_token.clone(),
                    trade_pda.clone(),
                    token_program.clone(),
                ],
                &[signer_seeds],
            )?;
        }

        if state.protocol_fee > 0 {
            invoke_signed(
                &token_instruction::transfer_checked(
                    token_program.key,
                    vault.key,
                    mint.key,
                    treasury_token.key,
                    trade_pda.key,
                    &[],
                    state.protocol_fee,
                    mint_state.decimals,
                )?,
                &[
                    vault.clone(),
                    mint.clone(),
                    treasury_token.clone(),
                    trade_pda.clone(),
                    token_program.clone(),
                ],
                &[signer_seeds],
            )?;
        }

        if state.shipping_amount > 0 {
            let carrier_token = next_account_info(accounts_iter)?;
            if !carrier_token.is_writable
                || carrier_token.owner != token_program.key
                || state.carrier == Pubkey::default()
            {
                return Err(FoodRescueError::InvalidAccount.into());
            }
            let carrier_destination = TokenAccount::unpack(&carrier_token.try_borrow_data()?)?;
            if carrier_destination.owner != state.carrier || carrier_destination.mint != state.mint
            {
                return Err(FoodRescueError::InvalidTokenAccount.into());
            }
            invoke_signed(
                &token_instruction::transfer_checked(
                    token_program.key,
                    vault.key,
                    mint.key,
                    carrier_token.key,
                    trade_pda.key,
                    &[],
                    state.shipping_amount,
                    mint_state.decimals,
                )?,
                &[
                    vault.clone(),
                    mint.clone(),
                    carrier_token.clone(),
                    trade_pda.clone(),
                    token_program.clone(),
                ],
                &[signer_seeds],
            )?;
        }

        let excess = escrow
            .amount
            .checked_sub(state.total_amount)
            .ok_or(FoodRescueError::ArithmeticOverflow)?;
        if excess > 0 {
            invoke_signed(
                &token_instruction::transfer_checked(
                    token_program.key,
                    vault.key,
                    mint.key,
                    buyer_token.key,
                    trade_pda.key,
                    &[],
                    excess,
                    mint_state.decimals,
                )?,
                &[
                    vault.clone(),
                    mint.clone(),
                    buyer_token.clone(),
                    trade_pda.clone(),
                    token_program.clone(),
                ],
                &[signer_seeds],
            )?;
        }

        state.status = TradeState::STATUS_SETTLED;
        state.pack(&mut trade_pda.try_borrow_mut_data()?)?;
        Ok(())
    }

    fn cancel_trade(program_id: &Pubkey, accounts: &[AccountInfo]) -> ProgramResult {
        let accounts_iter = &mut accounts.iter();
        let buyer = next_account_info(accounts_iter)?;
        let producer = next_account_info(accounts_iter)?;
        let trade_pda = next_account_info(accounts_iter)?;
        let vault = next_account_info(accounts_iter)?;
        let buyer_token = next_account_info(accounts_iter)?;
        let mint = next_account_info(accounts_iter)?;
        let token_program = next_account_info(accounts_iter)?;

        if !trade_pda.is_writable || !vault.is_writable || !buyer_token.is_writable {
            return Err(FoodRescueError::InvalidAccount.into());
        }
        if trade_pda.owner != program_id
            || token_program.key != &spl_token::id()
            || mint.owner != token_program.key
        {
            return Err(FoodRescueError::InvalidAccount.into());
        }

        let mut state = TradeState::unpack(&trade_pda.try_borrow_data()?)?;
        if state.status != TradeState::STATUS_INITIALIZED
            && state.status != TradeState::STATUS_FUNDED
        {
            return Err(FoodRescueError::InvalidState.into());
        }
        if *buyer.key != state.buyer || *producer.key != state.producer {
            return Err(FoodRescueError::InvalidAccount.into());
        }

        if state.status == TradeState::STATUS_FUNDED {
            if !buyer.is_signer || !producer.is_signer {
                return Err(FoodRescueError::InvalidAccount.into());
            }
        } else if !buyer.is_signer && !producer.is_signer {
            return Err(FoodRescueError::InvalidAccount.into());
        }

        if state.mint != *mint.key || state.vault != *vault.key {
            return Err(FoodRescueError::InvalidTokenAccount.into());
        }

        let trade_id_bytes = state.trade_id.to_le_bytes();
        let expected_trade = Pubkey::create_program_address(
            &[Self::TRADE_SEED, &trade_id_bytes, &[state.trade_bump]],
            program_id,
        )
        .map_err(|_| FoodRescueError::InvalidPda)?;
        let expected_vault = Pubkey::create_program_address(
            &[Self::VAULT_SEED, &trade_id_bytes, &[state.vault_bump]],
            program_id,
        )
        .map_err(|_| FoodRescueError::InvalidPda)?;
        if expected_trade != *trade_pda.key || expected_vault != *vault.key {
            return Err(FoodRescueError::InvalidPda.into());
        }

        if vault.owner != token_program.key || buyer_token.owner != token_program.key {
            return Err(FoodRescueError::InvalidTokenAccount.into());
        }
        let escrow = TokenAccount::unpack(&vault.try_borrow_data()?)?;
        let buyer_destination = TokenAccount::unpack(&buyer_token.try_borrow_data()?)?;
        let mint_state = Mint::unpack(&mint.try_borrow_data()?)?;
        if escrow.owner != *trade_pda.key || escrow.mint != state.mint {
            return Err(FoodRescueError::InvalidTokenAccount.into());
        }
        if buyer_destination.owner != state.buyer || buyer_destination.mint != state.mint {
            return Err(FoodRescueError::InvalidTokenAccount.into());
        }

        let expected_vault_amount = if state.status == TradeState::STATUS_FUNDED {
            state.total_amount
        } else {
            0
        };
        if escrow.amount < expected_vault_amount {
            return Err(FoodRescueError::InvalidTokenAccount.into());
        }

        // Anyone can send extra SPL tokens to a vault. Return its entire balance
        // so an unsolicited transfer cannot permanently block cancellation.
        let refund_amount = escrow.amount;
        if refund_amount > 0 {
            let trade_bump_seed = [state.trade_bump];
            let signer_seeds: &[&[u8]] = &[Self::TRADE_SEED, &trade_id_bytes, &trade_bump_seed];
            invoke_signed(
                &token_instruction::transfer_checked(
                    token_program.key,
                    vault.key,
                    mint.key,
                    buyer_token.key,
                    trade_pda.key,
                    &[],
                    refund_amount,
                    mint_state.decimals,
                )?,
                &[
                    vault.clone(),
                    mint.clone(),
                    buyer_token.clone(),
                    trade_pda.clone(),
                    token_program.clone(),
                ],
                &[signer_seeds],
            )?;
        }

        state.status = TradeState::STATUS_CANCELLED;
        state.pack(&mut trade_pda.try_borrow_mut_data()?)?;
        Ok(())
    }

    fn advance_delivery(
        program_id: &Pubkey,
        accounts: &[AccountInfo],
        from: u8,
        to: u8,
        transport_actor: bool,
    ) -> ProgramResult {
        let accounts_iter = &mut accounts.iter();
        let actor = next_account_info(accounts_iter)?;
        let trade_pda = next_account_info(accounts_iter)?;

        if !actor.is_signer || !trade_pda.is_writable || trade_pda.owner != program_id {
            return Err(FoodRescueError::InvalidAccount.into());
        }

        let mut state = TradeState::unpack(&trade_pda.try_borrow_data()?)?;
        if state.status != from {
            return Err(FoodRescueError::InvalidState.into());
        }

        let trade_id_bytes = state.trade_id.to_le_bytes();
        let expected_trade = Pubkey::create_program_address(
            &[Self::TRADE_SEED, &trade_id_bytes, &[state.trade_bump]],
            program_id,
        )
        .map_err(|_| FoodRescueError::InvalidPda)?;
        if expected_trade != *trade_pda.key {
            return Err(FoodRescueError::InvalidPda.into());
        }

        let expected_actor = if transport_actor {
            if state.carrier == Pubkey::default() {
                state.buyer
            } else {
                state.carrier
            }
        } else {
            state.producer
        };
        if *actor.key != expected_actor {
            return Err(FoodRescueError::InvalidAccount.into());
        }

        state.status = to;
        state.pack(&mut trade_pda.try_borrow_mut_data()?)?;
        Ok(())
    }

    fn create_rescue_proof(
        program_id: &Pubkey,
        accounts: &[AccountInfo],
        trade_id: u64,
        carrier: Pubkey,
        metadata_hash: [u8; 32],
    ) -> ProgramResult {
        let accounts_iter = &mut accounts.iter();
        let ngo = next_account_info(accounts_iter)?;
        let producer = next_account_info(accounts_iter)?;
        let authority = next_account_info(accounts_iter)?;
        let protocol_config = next_account_info(accounts_iter)?;
        let rescue_pda = next_account_info(accounts_iter)?;
        let system = next_account_info(accounts_iter)?;

        if !ngo.is_signer
            || !ngo.is_writable
            || !producer.is_signer
            || !authority.is_signer
            || !rescue_pda.is_writable
        {
            return Err(FoodRescueError::InvalidAccount.into());
        }
        if system.key != &system_program::id()
            || protocol_config.owner != program_id
            || ngo.key == producer.key
            || metadata_hash == [0; 32]
        {
            return Err(FoodRescueError::InvalidAccount.into());
        }

        let protocol = ProtocolConfig::unpack(&protocol_config.try_borrow_data()?)?;
        let expected_protocol = Pubkey::create_program_address(
            &[
                Self::PROTOCOL_SEED,
                protocol.authority.as_ref(),
                &[protocol.bump],
            ],
            program_id,
        )
        .map_err(|_| FoodRescueError::InvalidPda)?;
        if expected_protocol != *protocol_config.key || protocol.authority != *authority.key {
            return Err(FoodRescueError::InvalidPda.into());
        }

        let trade_id_bytes = trade_id.to_le_bytes();
        let (expected_rescue, bump) =
            Pubkey::find_program_address(&[Self::RESCUE_SEED, &trade_id_bytes], program_id);
        if rescue_pda.key != &expected_rescue {
            return Err(FoodRescueError::InvalidPda.into());
        }

        let rent = Rent::get()?;
        invoke_signed(
            &system_instruction::create_account(
                ngo.key,
                rescue_pda.key,
                rent.minimum_balance(RescueProofState::LEN),
                RescueProofState::LEN as u64,
                program_id,
            ),
            &[ngo.clone(), rescue_pda.clone(), system.clone()],
            &[&[Self::RESCUE_SEED, &trade_id_bytes, &[bump]]],
        )?;

        RescueProofState {
            version: RescueProofState::VERSION,
            bump,
            trade_id,
            producer: *producer.key,
            ngo: *ngo.key,
            carrier,
            metadata_hash,
            created_at: Clock::get()?.unix_timestamp,
        }
        .pack(&mut rescue_pda.try_borrow_mut_data()?)?;

        Ok(())
    }
}
