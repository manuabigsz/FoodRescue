use foodrescue::state::{ProtocolConfig, RescueProofState, TradeState};
use solana_program::pubkey::Pubkey;

fn main() {
    let key = |byte| Pubkey::new_from_array([byte; 32]);
    let trade = TradeState {
        version: 2,
        trade_bump: 254,
        vault_bump: 253,
        trade_id: 42,
        buyer: key(1),
        producer: key(2),
        carrier: key(3),
        mint: key(4),
        vault: key(5),
        protocol_config: key(6),
        product_amount: 100_123_456,
        shipping_amount: 12_345_678,
        protocol_fee: 2_002_469,
        total_amount: 112_469_134,
        expires_at: 2_000_000_000,
        status: TradeState::STATUS_FUNDED,
    };
    let protocol = ProtocolConfig {
        version: 1,
        bump: 250,
        authority: key(7),
        treasury: key(8),
        mint: key(4),
    };
    let proof = RescueProofState {
        version: 1,
        bump: 249,
        trade_id: 42,
        producer: key(2),
        ngo: key(1),
        carrier: key(3),
        metadata_hash: [10; 32],
        created_at: 2_000_000_001,
    };
    std::fs::create_dir_all("fixtures").unwrap();
    let mut raw = vec![0; TradeState::LEN];
    trade.pack(&mut raw).unwrap();
    std::fs::write("fixtures/trade.bin", raw).unwrap();
    let mut raw = vec![0; ProtocolConfig::LEN];
    protocol.pack(&mut raw).unwrap();
    std::fs::write("fixtures/protocol.bin", raw).unwrap();
    let mut raw = vec![0; RescueProofState::LEN];
    proof.pack(&mut raw).unwrap();
    std::fs::write("fixtures/rescue.bin", raw).unwrap();
}
