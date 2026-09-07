# FoodRescue — Donations, NGO and Proof of Rescue

## Donation acceptance

An open surplus with `donation_eligible=true` can be accepted only by a user with the `ngo` role:

```http
POST /api/v1/surplus/{surplusLot}/donations/accept
```

The resulting trade reuses `buyer_id` as the recipient/payer foreign key for backward compatibility, while the API exposes `recipient_id` and `recipient_type=ngo`.

Donation financial values are always:

```text
product_amount = 0
protocol_fee   = 0
```

## Logistics

The NGO can either:

- request third-party carrier quotations using the existing shipping endpoints; or
- manage transport itself with:

```http
POST /api/v1/trades/{trade}/ngo-managed
```

If a carrier is selected, only the freight is funded into the Solana escrow.

If the NGO manages transport itself:

```text
shipping_amount = 0
```

and the trade advances directly to `FUNDED` as an operational state without creating a blockchain escrow account.

## Delivery

The producer marks the donation ready for pickup. The selected carrier confirms pickup/delivery; if there is no carrier, the NGO performs those transitions.

```text
FUNDED
  -> READY_FOR_PICKUP
  -> IN_TRANSIT
  -> DELIVERED
```

## Carrier donation settlement

When a third-party carrier is used, the existing `initialize_trade`, `fund_trade`, and `settle_trade` flow is reused with:

```text
product_amount = 0
protocol_fee   = 0
shipping_amount > 0
```

Therefore the settlement transfers only freight to the selected carrier. No producer payment and no protocol fee are made.

## Proof of Rescue

After delivery, the NGO can create an on-chain Proof of Rescue:

```http
POST /api/v1/trades/{trade}/rescue-proof/prepare
POST /api/v1/trades/{trade}/rescue-proof/confirm
GET  /api/v1/trades/{trade}/rescue-proof
```

The Solana instruction is `create_rescue_proof` (tag `5`).

PDA:

```text
["foodrescue_rescue", trade_id_u64_le]
```

Both the NGO and producer must sign the same transaction. This prevents either side from creating a unilateral rescue attestation.

On-chain state stores:

- trade id
- producer wallet
- NGO wallet
- carrier wallet or zero pubkey
- SHA-256 commitment to canonical off-chain rescue metadata
- creation timestamp

The canonical metadata contains the trade/lot/product identifiers, quantity, unit, producer, NGO, selected carrier and shipping amount. The complete metadata remains in PostgreSQL while its SHA-256 commitment is stored on-chain.

For NGO-managed transport, confirmation of the Proof of Rescue changes:

```text
DELIVERED -> COMPLETED
surplus   -> DONATED
```

For carrier transport, financial settlement pays only the freight and changes the trade to `PROOF_PENDING`. The NGO and producer must then confirm the Proof of Rescue; only after that confirmation does the trade become `COMPLETED` and the surplus become `DONATED`.
