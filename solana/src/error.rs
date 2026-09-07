use solana_program::program_error::ProgramError;

#[repr(u32)]
#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum FoodRescueError {
    InvalidInstruction = 0,
    InvalidPda = 1,
    InvalidAccount = 2,
    InvalidState = 3,
    InvalidFee = 4,
    PaymentExpired = 5,
    ArithmeticOverflow = 6,
    InvalidTokenAccount = 7,
}

impl From<FoodRescueError> for ProgramError {
    fn from(error: FoodRescueError) -> Self {
        ProgramError::Custom(error as u32)
    }
}
