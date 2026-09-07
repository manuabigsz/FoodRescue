# FoodRescue — Delivery lifecycle and settlement

## Commercial lifecycle

After the escrow is funded, the operational states are:

```text
FUNDED
  ↓ producer
READY_FOR_PICKUP
  ↓ selected carrier, or buyer in BUYER_MANAGED
IN_TRANSIT
  ↓ selected carrier, or buyer in BUYER_MANAGED
DELIVERED
  ↓ buyer signs settlement
COMPLETED
```

### Endpoints

```http
POST /api/v1/trades/{trade}/delivery/ready-for-pickup
POST /api/v1/trades/{trade}/delivery/pickup
POST /api/v1/trades/{trade}/delivery/delivered

POST /api/v1/trades/{trade}/blockchain/settlement/prepare
POST /api/v1/trades/{trade}/blockchain/settlement/confirm
```

The buyer confirmation is the final MVP settlement event. Disputes, arbitration, partial refunds, quantity divergence and quality divergence remain out of scope.

## On-chain settlement

`settle_trade` is instruction tag `3`.

The buyer is the required signer. The trade PDA is the authority of the SPL escrow vault.

The program validates:

- trade is FUNDED on-chain;
- signer is the original buyer;
- trade PDA and vault PDA are correct;
- protocol config belongs to FoodRescue;
- mint matches the protocol and trade;
- producer token account belongs to the producer wallet and mint;
- treasury token account belongs to the treasury stored in ProtocolConfig and mint;
- carrier token account belongs to the selected carrier and mint when freight is non-zero;
- vault contains at least `product_amount + shipping_amount` before settlement; any excess is returned to the buyer token account by the settlement instruction.

Split:

```text
producer = product_amount - protocol_fee
protocol treasury = protocol_fee
carrier = shipping_amount
```

For BUYER_MANAGED:

```text
shipping_amount = 0
carrier transfer = 0
```

After all transfers, TradeState moves from `STATUS_FUNDED = 1` to `STATUS_SETTLED = 2`. The vault remains allocated but must have zero token balance. The backend only changes the database trade to COMPLETED after verifying the confirmed transaction, settled TradeState and zero vault balance through Solana RPC.

## Recipient token accounts

The settlement preparation response does not trust token account addresses supplied by the backend. The frontend derives/creates SPL token accounts for the required owner wallets and mint. The Solana program validates their actual SPL owner and mint before transferring funds.

No private key is stored by Laravel.
