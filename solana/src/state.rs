use crate::error::FoodRescueError;
use solana_program::{program_error::ProgramError, pubkey::Pubkey};

#[derive(Clone, Debug, Eq, PartialEq)]
pub struct ProtocolConfig {
    pub version: u8,
    pub bump: u8,
    pub authority: Pubkey,
    pub treasury: Pubkey,
    pub mint: Pubkey,
}

impl ProtocolConfig {
    pub const VERSION: u8 = 1;
    pub const LEN: usize = 98;

    pub fn unpack(input: &[u8]) -> Result<Self, ProgramError> {
        if input.len() != Self::LEN {
            return Err(FoodRescueError::InvalidState.into());
        }
        let mut offset = 0;
        let version = input[offset];
        offset += 1;
        let bump = input[offset];
        offset += 1;
        let authority = take_pubkey(input, &mut offset)?;
        let treasury = take_pubkey(input, &mut offset)?;
        let mint = take_pubkey(input, &mut offset)?;
        if version != Self::VERSION {
            return Err(FoodRescueError::InvalidState.into());
        }
        Ok(Self {
            version,
            bump,
            authority,
            treasury,
            mint,
        })
    }

    pub fn pack(&self, output: &mut [u8]) -> Result<(), ProgramError> {
        if output.len() != Self::LEN {
            return Err(FoodRescueError::InvalidState.into());
        }
        let mut offset = 0;
        output[offset] = self.version;
        offset += 1;
        output[offset] = self.bump;
        offset += 1;
        put(output, &mut offset, self.authority.as_ref())?;
        put(output, &mut offset, self.treasury.as_ref())?;
        put(output, &mut offset, self.mint.as_ref())?;
        Ok(())
    }
}

#[derive(Clone, Debug, Eq, PartialEq)]
pub struct TradeState {
    pub version: u8,
    pub trade_bump: u8,
    pub vault_bump: u8,
    pub trade_id: u64,
    pub buyer: Pubkey,
    pub producer: Pubkey,
    pub carrier: Pubkey,
    pub mint: Pubkey,
    pub vault: Pubkey,
    pub protocol_config: Pubkey,
    pub product_amount: u64,
    pub shipping_amount: u64,
    pub protocol_fee: u64,
    pub total_amount: u64,
    pub expires_at: i64,
    pub status: u8,
}

impl TradeState {
    pub const VERSION: u8 = 2;
    pub const STATUS_INITIALIZED: u8 = 0;
    pub const STATUS_FUNDED: u8 = 1;
    pub const STATUS_SETTLED: u8 = 2;
    pub const STATUS_CANCELLED: u8 = 3;
    pub const STATUS_READY_FOR_PICKUP: u8 = 4;
    pub const STATUS_IN_TRANSIT: u8 = 5;
    pub const STATUS_DELIVERED: u8 = 6;
    pub const LEN: usize = 244;

    pub fn unpack(input: &[u8]) -> Result<Self, ProgramError> {
        if input.len() != Self::LEN {
            return Err(FoodRescueError::InvalidState.into());
        }
        let mut offset = 0;
        let version = input[offset];
        offset += 1;
        let trade_bump = input[offset];
        offset += 1;
        let vault_bump = input[offset];
        offset += 1;
        let trade_id = take_u64(input, &mut offset)?;
        let buyer = take_pubkey(input, &mut offset)?;
        let producer = take_pubkey(input, &mut offset)?;
        let carrier = take_pubkey(input, &mut offset)?;
        let mint = take_pubkey(input, &mut offset)?;
        let vault = take_pubkey(input, &mut offset)?;
        let protocol_config = take_pubkey(input, &mut offset)?;
        let product_amount = take_u64(input, &mut offset)?;
        let shipping_amount = take_u64(input, &mut offset)?;
        let protocol_fee = take_u64(input, &mut offset)?;
        let total_amount = take_u64(input, &mut offset)?;
        let expires_at = take_i64(input, &mut offset)?;
        let status = input[offset];

        if version != Self::VERSION {
            return Err(FoodRescueError::InvalidState.into());
        }

        Ok(Self {
            version,
            trade_bump,
            vault_bump,
            trade_id,
            buyer,
            producer,
            carrier,
            mint,
            vault,
            protocol_config,
            product_amount,
            shipping_amount,
            protocol_fee,
            total_amount,
            expires_at,
            status,
        })
    }

    pub fn pack(&self, output: &mut [u8]) -> Result<(), ProgramError> {
        if output.len() != Self::LEN {
            return Err(FoodRescueError::InvalidState.into());
        }
        let mut offset = 0;
        output[offset] = self.version;
        offset += 1;
        output[offset] = self.trade_bump;
        offset += 1;
        output[offset] = self.vault_bump;
        offset += 1;
        put(output, &mut offset, &self.trade_id.to_le_bytes())?;
        put(output, &mut offset, self.buyer.as_ref())?;
        put(output, &mut offset, self.producer.as_ref())?;
        put(output, &mut offset, self.carrier.as_ref())?;
        put(output, &mut offset, self.mint.as_ref())?;
        put(output, &mut offset, self.vault.as_ref())?;
        put(output, &mut offset, self.protocol_config.as_ref())?;
        put(output, &mut offset, &self.product_amount.to_le_bytes())?;
        put(output, &mut offset, &self.shipping_amount.to_le_bytes())?;
        put(output, &mut offset, &self.protocol_fee.to_le_bytes())?;
        put(output, &mut offset, &self.total_amount.to_le_bytes())?;
        put(output, &mut offset, &self.expires_at.to_le_bytes())?;
        output[offset] = self.status;
        Ok(())
    }
}

#[derive(Clone, Debug, Eq, PartialEq)]
pub struct RescueProofState {
    pub version: u8,
    pub bump: u8,
    pub trade_id: u64,
    pub producer: Pubkey,
    pub ngo: Pubkey,
    pub carrier: Pubkey,
    pub metadata_hash: [u8; 32],
    pub created_at: i64,
    pub status: u8,
}

impl RescueProofState {
    pub const VERSION: u8 = 2;
    pub const LEN: usize = 147;

    /// A NGO abriu a atestação; falta o produtor confirmar.
    pub const STATUS_PENDING_PRODUCER: u8 = 0;

    /// As duas partes atestaram; o resgate está completo.
    pub const STATUS_CONFIRMED: u8 = 1;

    pub fn unpack(input: &[u8]) -> Result<Self, ProgramError> {
        if input.len() != Self::LEN {
            return Err(FoodRescueError::InvalidState.into());
        }
        let mut offset = 0;
        let version = input[offset];
        offset += 1;
        let bump = input[offset];
        offset += 1;
        let trade_id = take_u64(input, &mut offset)?;
        let producer = take_pubkey(input, &mut offset)?;
        let ngo = take_pubkey(input, &mut offset)?;
        let carrier = take_pubkey(input, &mut offset)?;
        let metadata_hash: [u8; 32] = input
            .get(offset..offset + 32)
            .ok_or(FoodRescueError::InvalidState)?
            .try_into()
            .map_err(|_| FoodRescueError::InvalidState)?;
        offset += 32;
        let created_at = take_i64(input, &mut offset)?;
        let status = input[offset];

        if version != Self::VERSION {
            return Err(FoodRescueError::InvalidState.into());
        }

        Ok(Self {
            version,
            bump,
            trade_id,
            producer,
            ngo,
            carrier,
            metadata_hash,
            created_at,
            status,
        })
    }

    pub fn pack(&self, output: &mut [u8]) -> Result<(), ProgramError> {
        if output.len() != Self::LEN {
            return Err(FoodRescueError::InvalidState.into());
        }
        let mut offset = 0;
        output[offset] = self.version;
        offset += 1;
        output[offset] = self.bump;
        offset += 1;
        put(output, &mut offset, &self.trade_id.to_le_bytes())?;
        put(output, &mut offset, self.producer.as_ref())?;
        put(output, &mut offset, self.ngo.as_ref())?;
        put(output, &mut offset, self.carrier.as_ref())?;
        put(output, &mut offset, &self.metadata_hash)?;
        put(output, &mut offset, &self.created_at.to_le_bytes())?;
        output[offset] = self.status;
        Ok(())
    }
}

fn take_u64(input: &[u8], offset: &mut usize) -> Result<u64, ProgramError> {
    let bytes: [u8; 8] = input
        .get(*offset..*offset + 8)
        .ok_or(FoodRescueError::InvalidState)?
        .try_into()
        .map_err(|_| FoodRescueError::InvalidState)?;
    *offset += 8;
    Ok(u64::from_le_bytes(bytes))
}

fn take_i64(input: &[u8], offset: &mut usize) -> Result<i64, ProgramError> {
    let bytes: [u8; 8] = input
        .get(*offset..*offset + 8)
        .ok_or(FoodRescueError::InvalidState)?
        .try_into()
        .map_err(|_| FoodRescueError::InvalidState)?;
    *offset += 8;
    Ok(i64::from_le_bytes(bytes))
}

fn take_pubkey(input: &[u8], offset: &mut usize) -> Result<Pubkey, ProgramError> {
    let bytes: [u8; 32] = input
        .get(*offset..*offset + 32)
        .ok_or(FoodRescueError::InvalidState)?
        .try_into()
        .map_err(|_| FoodRescueError::InvalidState)?;
    *offset += 32;
    Ok(Pubkey::new_from_array(bytes))
}

fn put(output: &mut [u8], offset: &mut usize, bytes: &[u8]) -> Result<(), ProgramError> {
    let destination = output
        .get_mut(*offset..*offset + bytes.len())
        .ok_or(FoodRescueError::InvalidState)?;
    destination.copy_from_slice(bytes);
    *offset += bytes.len();
    Ok(())
}
