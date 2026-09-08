use crate::error::FoodRescueError;
use solana_program::{program_error::ProgramError, pubkey::Pubkey};

pub enum FoodRescueInstruction {
    InitializeTrade {
        trade_id: u64,
        product_amount: u64,
        shipping_amount: u64,
        protocol_fee: u64,
        expires_at: i64,
        producer: Pubkey,
        carrier: Pubkey,
    },
    FundTrade,
    InitializeProtocol {
        treasury: Pubkey,
    },
    SettleTrade,
    CancelTrade,
    MarkReadyForPickup,
    ConfirmPickup,
    MarkDelivered,
    CreateRescueProof {
        trade_id: u64,
        carrier: Pubkey,
        metadata_hash: [u8; 32],
    },
    ConfirmRescueProof,
}

impl FoodRescueInstruction {
    pub fn unpack(input: &[u8]) -> Result<Self, ProgramError> {
        let (&tag, rest) = input
            .split_first()
            .ok_or(FoodRescueError::InvalidInstruction)?;
        match tag {
            0 if rest.len() == 104 => Ok(Self::InitializeTrade {
                trade_id: read_u64(rest, 0)?,
                product_amount: read_u64(rest, 8)?,
                shipping_amount: read_u64(rest, 16)?,
                protocol_fee: read_u64(rest, 24)?,
                expires_at: read_i64(rest, 32)?,
                producer: read_pubkey(rest, 40)?,
                carrier: read_pubkey(rest, 72)?,
            }),
            1 if rest.is_empty() => Ok(Self::FundTrade),
            2 if rest.len() == 32 => Ok(Self::InitializeProtocol {
                treasury: read_pubkey(rest, 0)?,
            }),
            3 if rest.is_empty() => Ok(Self::SettleTrade),
            4 if rest.is_empty() => Ok(Self::CancelTrade),
            6 if rest.is_empty() => Ok(Self::MarkReadyForPickup),
            7 if rest.is_empty() => Ok(Self::ConfirmPickup),
            8 if rest.is_empty() => Ok(Self::MarkDelivered),
            9 if rest.is_empty() => Ok(Self::ConfirmRescueProof),
            5 if rest.len() == 72 => {
                let hash: [u8; 32] = rest
                    .get(40..72)
                    .ok_or(FoodRescueError::InvalidInstruction)?
                    .try_into()
                    .map_err(|_| FoodRescueError::InvalidInstruction)?;
                Ok(Self::CreateRescueProof {
                    trade_id: read_u64(rest, 0)?,
                    carrier: read_pubkey(rest, 8)?,
                    metadata_hash: hash,
                })
            }
            _ => Err(FoodRescueError::InvalidInstruction.into()),
        }
    }
}

fn read_u64(input: &[u8], offset: usize) -> Result<u64, ProgramError> {
    let bytes: [u8; 8] = input
        .get(offset..offset + 8)
        .ok_or(FoodRescueError::InvalidInstruction)?
        .try_into()
        .map_err(|_| FoodRescueError::InvalidInstruction)?;
    Ok(u64::from_le_bytes(bytes))
}
fn read_i64(input: &[u8], offset: usize) -> Result<i64, ProgramError> {
    let bytes: [u8; 8] = input
        .get(offset..offset + 8)
        .ok_or(FoodRescueError::InvalidInstruction)?
        .try_into()
        .map_err(|_| FoodRescueError::InvalidInstruction)?;
    Ok(i64::from_le_bytes(bytes))
}
fn read_pubkey(input: &[u8], offset: usize) -> Result<Pubkey, ProgramError> {
    let bytes: [u8; 32] = input
        .get(offset..offset + 32)
        .ok_or(FoodRescueError::InvalidInstruction)?
        .try_into()
        .map_err(|_| FoodRescueError::InvalidInstruction)?;
    Ok(Pubkey::new_from_array(bytes))
}
