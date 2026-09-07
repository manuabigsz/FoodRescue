# FoodRescue — Implementation Summary

## 1. Overview

FoodRescue is a marketplace/protocol for **actual agricultural surplus that already exists**.

It is not intended for:
- production forecasting;
- normal agricultural procurement;
- future crop planning;
- speculative supply;
- auction mechanisms.

The platform starts when a producer already has confirmed surplus available and needs an economic or social destination before it becomes waste.

Primary positioning:

> A decentralized network that finds an economic or social destination for agricultural surplus before it becomes waste.

---

## 2. Current MVP Stack

Backend:
- PHP 8.5
- Laravel 13
- REST API
- Laravel Sanctum
- Spatie Permission
- PostgreSQL 17

Laravel infrastructure:
- Queue: PostgreSQL
- Cache: PostgreSQL
- Session: PostgreSQL
- Scheduler: Laravel Scheduler

Frontend:
- Laravel project
- Node 24
- Vite
- Tailwind / JS assets

Blockchain:
- Solana Devnet
- Native Rust Solana program
- No Anchor
- SPL Token
- Test token/mint used as development USDC equivalent

Development environment:
- Docker
- ServerSideUp PHP FPM + NGINX
- PostgreSQL
- Vite container
- Solana/Rust tool container
- No Redis
- No Mailpit
- No local Solana validator

---

## 3. Roles / Actors

The platform has five roles:

- Admin
- Producer
- Buyer
- Carrier
- NGO

Authentication identity remains in `users`.

Specific actor information is stored in separate profile tables:

- `producer_profiles`
- `buyer_profiles`
- `carrier_profiles`
- `ngo_profiles`

### Producer profile

Includes fields such as:
- producer type;
- organization name;
- farm/property name;
- document number;
- phone;
- country/state/city;
- address;
- postal code.

### Buyer profile

Includes:
- buyer type;
- organization;
- document;
- contact;
- location.

### Carrier profile

Includes:
- company;
- document;
- responsible person;
- service regions;
- vehicle types;
- maximum capacity;
- location.

### NGO profile

Includes:
- organization;
- registration number;
- responsible person;
- description;
- location.

This is deliberately **not full KYC**.

---

## 4. Wallet Ownership Verification

A Solana wallet address alone is not considered sufficient.

Each actor must prove ownership of the configured wallet through an Ed25519 signature challenge.

Relevant user data:

- `solana_wallet_address`
- `solana_wallet_verified_at`

Wallet challenges are stored separately and include:
- user when applicable;
- wallet;
- nonce;
- expiration;
- used timestamp.

Rules:
- challenge has short expiration;
- nonce is unique;
- challenge can only be used once;
- signature must match the exact message;
- wallet must match the challenge;
- changing wallet requires a new verification;
- private keys and seed phrases are never stored.

Routes:

```http
POST /api/v1/auth/wallet/challenge
POST /api/v1/auth/wallet/change/challenge
POST /api/v1/auth/wallet/change/verify
```

Registration also consumes the challenge/signature to confirm wallet ownership.

---

## 5. Admin

Admin capabilities include:
- user management;
- approve/block accounts;
- agricultural product management;
- quality grade management;
- view operational data;
- configure application timeouts;
- initialize/confirm blockchain protocol configuration;
- impact/dashboard views.

Timeout settings:
- shipping quotation: 240 minutes;
- payment funding: 15 minutes.

They are stored/configurable in the application/database, not hard-coded in `.env`.

---

## 6. Agricultural Catalog

Implemented concepts:
- agricultural products;
- quality grades/categories.

Admin routes exist to:
- list;
- create;
- update.

---

## 7. Surplus Lots

A producer can register actual surplus.

Main fields include:
- product;
- quantity;
- unit;
- origin;
- harvest date;
- deadline/availability;
- quality;
- asking price;
- private minimum price;
- donation eligibility;
- supported logistics modes;
- status.

The producer's private minimum price must never be exposed through marketplace resources.

Typical statuses:

- OPEN
- RESERVED
- SOLD
- DONATED
- EXPIRED
- CANCELLED

Marketplace supports filtering by:
- product;
- location;
- price;
- quantity;
- quality;
- deadline;
- donation eligibility.

---

## 8. Offers and Buy Now

Commercial mechanism:
- direct purchase;
- buyer offer/proposal.

No auction.

Buyer actions:
- buy at asking price;
- make an offer.

Producer can:
- accept offer;
- reject offer.

Lot splitting is not supported in MVP.

A lot is reserved atomically when an offer is accepted or buy-now succeeds.

Concurrency protection uses database transaction + `lockForUpdate`.

---

## 9. Trades

A trade is created after buy-now or accepted offer.

Important monetary fields:
- `product_amount`
- `shipping_amount`
- `protocol_fee`

Commercial rule:

```text
protocol_fee = product_amount * 2%
seller_amount = product_amount - protocol_fee
buyer_total = product_amount + shipping_amount
```

The protocol fee is included inside product value.

For donation:

```text
product_amount = 0
protocol_fee = 0
shipping_amount >= 0
```

---

## 10. Trade State Machine

Implemented conceptual states include:

- RESERVED
- SHIPPING_QUOTATION
- CARRIER_SELECTED
- BUYER_MANAGED
- NGO_MANAGED
- WAITING_PAYMENT
- FUNDED
- READY_FOR_PICKUP
- IN_TRANSIT
- DELIVERED
- COMPLETED
- CANCELLED
- EXPIRED

State transition rules are enforced by backend services/controllers/policies.

---

## 11. Logistics

After negotiation:
1. buyer/NGO provides destination;
2. shipping request is created;
3. carriers can submit offers;
4. buyer/NGO selects a carrier or chooses self-managed transport.

Shipping request retains lot:
- quantity;
- unit;
- origin;
- destination.

It does not blindly convert everything into `weight_kg`.

### Carrier offer

Contains:
- freight amount;
- pickup availability;
- estimated delivery;
- expiration.

### Timeout

Shipping quotation timeout:
- default 4 hours;
- configurable by admin.

If no carrier is selected before timeout:
- commercial trade → `BUYER_MANAGED`;
- donation → corresponding self-managed path as implemented.

After logistics is resolved:
- trade enters `WAITING_PAYMENT`.

Payment timeout:
- default 15 minutes.

If not funded:
- trade expires;
- selected carrier is released;
- lot returns to OPEN when appropriate.

Scheduler handles timeout expiration.

---

## 12. Solana Protocol Configuration

A dedicated on-chain `ProtocolConfig PDA` exists.

It stores:
- authority;
- official treasury;
- mint;
- version/bump.

Conceptual seeds:

```text
["foodrescue_protocol", authority_pubkey]
```

Relevant configuration:

```dotenv
SOLANA_PROTOCOL_AUTHORITY=
SOLANA_PROTOCOL_TREASURY=
SOLANA_TOKEN_MINT=
SOLANA_PROGRAM_ID=
```

The treasury is therefore not trusted from arbitrary frontend input.

Each on-chain trade references the protocol configuration used.

---

## 13. Solana Program

Native Rust program, no Anchor.

Implemented instructions:

- `initialize_protocol`
- `initialize_trade`
- `fund_trade`
- `settle_trade`
- `cancel_trade`
- `create_rescue_proof`

### Trade PDA

Conceptually derived from:

```text
["foodrescue_trade", trade_id_u64_le]
```

### Vault PDA

Conceptually derived from:

```text
["foodrescue_vault", trade_id_u64_le]
```

The trade state includes relevant immutable participants and monetary values.

---

## 14. Blockchain Funding

The Laravel backend does **not** sign financial transactions for users.

Flow:

1. Laravel prepares transaction/instruction data.
2. Frontend wallet signs.
3. Transaction is sent to Devnet.
4. Frontend sends signature back to Laravel.
5. Laravel queries Solana RPC.
6. Laravel verifies transaction and on-chain account state.
7. Trade is marked `FUNDED` only after validation.

Backend checks include:
- signature confirmed;
- transaction has no error;
- expected FoodRescue program was invoked;
- expected PDA was referenced;
- PDA owner is correct;
- trade id matches;
- buyer/producer/carrier match;
- mint matches;
- amounts match;
- deadline matches;
- funded state matches.

Blockchain transaction history is persisted in the database.

---

## 15. Delivery Lifecycle

Implemented commercial operational flow:

```text
FUNDED
  ↓
READY_FOR_PICKUP
  ↓
IN_TRANSIT
  ↓
DELIVERED
  ↓
settlement
  ↓
COMPLETED
```

Authorization:

- Producer marks ready for pickup.
- Selected Carrier confirms pickup and delivery.
- For `BUYER_MANAGED`, Buyer handles pickup/delivery transitions.
- For `NGO_MANAGED`, NGO handles corresponding transitions.
- Buyer confirms commercial settlement.

Delivery timestamps are persisted.

---

## 16. On-chain Settlement

`settle_trade` performs payout from escrow.

Commercial trade:

```text
Escrow Vault
├── 98% product value → Producer
├── 2% product value  → Protocol Treasury
└── shipping amount   → Carrier
```

If buyer-managed:
- shipping amount = 0;
- carrier account is not required.

On-chain validation includes:
- correct buyer signer;
- correct Trade PDA;
- correct vault;
- correct ProtocolConfig;
- correct SPL mint;
- producer token account belongs to producer;
- treasury token account belongs to configured treasury;
- carrier token account belongs to selected carrier;
- expected vault amount.

Laravel marks `COMPLETED` only after:
- transaction confirmation;
- on-chain trade state becomes SETTLED;
- vault balance is zero.

---

## 17. Cancellation / Refund

Cancellation exists both before and after blockchain initialization.

### Before funding / before blockchain state

Buyer or producer can cancel according to backend rules.

If buyer cancels:
- lot may return OPEN.

If producer cancels:
- lot becomes CANCELLED.

### On-chain initialized but not funded

Buyer OR producer can sign cancellation.

### Funded trade

Refund is intentionally conservative.

After funding:
- buyer AND producer must sign the same cancellation transaction.

This prevents unilateral on-chain refund from bypassing off-chain delivery state.

Refund:

```text
100% vault → Buyer
```

No protocol fee.
No carrier payment.

On-chain state becomes CANCELLED.

Backend records:
- cancelled_by;
- reason;
- cancelled_at;
- refunded_at when applicable.

---

## 18. Donations / NGO

Producer can mark lot as donation eligible.

NGO can accept donation.

For donation:

```text
product_amount = 0
protocol_fee = 0
```

Two logistics paths exist.

### Donation with Carrier

NGO pays only freight:

```text
shipping_amount > 0
```

Escrow contains only freight.

After delivery:
- carrier receives shipping amount;
- producer receives zero;
- protocol receives zero.

### NGO-managed transport

```text
shipping_amount = 0
```

No financial escrow is required.

Operational states continue to delivery.

---

## 19. Proof of Rescue

Implemented on-chain `create_rescue_proof`.

Conceptual PDA:

```text
["foodrescue_rescue", trade_id_u64_le]
```

Proof includes:
- trade id;
- producer;
- NGO;
- carrier if any;
- metadata hash;
- timestamp/version.

The metadata hash is derived from canonical operation metadata instead of storing all commercial data on-chain.

Proof requires:
- Producer signature;
- NGO signature.

This prevents unilateral rescue claims.

Rescue proof data/signature/PDA/slot/hash are also persisted off-chain.

After successful donation completion:
- trade becomes COMPLETED;
- surplus becomes DONATED.

---

## 20. Ratings and Reputation

Ratings are available only after completed trades.

Rules:
- integer rating from 1 to 5;
- optional comment;
- participant-only;
- no self-rating;
- one rating per `trade + reviewer + target`.

Allowed relations:

Commercial:
- Buyer ↔ Producer
- Buyer ↔ Carrier

Donation:
- NGO ↔ Producer
- NGO ↔ Carrier

Producer ↔ Carrier is not enabled in MVP.

Reputation is calculated dynamically from ratings:
- average;
- count.

No redundant average is stored in `users`.

Endpoints include:

```http
POST /api/v1/trades/{trade}/ratings
GET  /api/v1/users/{user}/ratings
GET  /api/v1/users/{user}/reputation
```

---

## 21. Dashboards and Impact Metrics

Dashboards exist for:

```http
GET /api/v1/dashboard/producer
GET /api/v1/dashboard/buyer
GET /api/v1/dashboard/carrier
GET /api/v1/dashboard/ngo
```

Admin:

```http
GET /api/v1/admin/dashboard
GET /api/v1/admin/impact
```

Metrics include:
- active surplus lots;
- completed commercial operations;
- completed donations;
- producer revenue recovered;
- product spend;
- freight spend/revenue;
- protocol fees;
- confirmed rescue proofs;
- trade counts by status;
- reputation;
- participant counts.

Impact quantities are grouped by unit.

For example:

```json
{
  "kg": "1250.000",
  "t": "8.000",
  "box": "40.000"
}
```

Do not aggregate incompatible units into one number unless an explicit trusted conversion is implemented later.

No analytics table is maintained in MVP; metrics are calculated from source data.

---

## 22. Important API Areas

Main API is versioned under:

```text
/api/v1
```

Relevant route areas:
- auth;
- admin;
- marketplace;
- trades;
- logistics;
- blockchain;
- ratings;
- dashboards.

Route files include:
- `routes/api.php`
- `routes/api/v1/index.php`
- `routes/api/v1/auth.php`
- `routes/api/v1/admin.php`
- `routes/api/v1/marketplace.php`

---

## 23. Security Decisions

Important current decisions:

- Sanctum authentication.
- Spatie roles/permissions.
- Actor-specific authorization.
- Wallet ownership proof via Ed25519 challenge.
- No private keys stored.
- Buyer signs financial wallet operations.
- Producer + NGO sign rescue proof.
- Buyer + producer both sign funded refunds.
- Private producer minimum price must not leak.
- Database transactions and `lockForUpdate` protect lot reservation.
- Solana signatures are not trusted without RPC/account validation.
- Official treasury comes from ProtocolConfig PDA.
- State transitions must be validated.
- Backend should reject unexpected request fields where current request pattern requires strict validation.

---

## 24. Relevant Environment Variables

Application:

```dotenv
APP_NAME="FoodRescue"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8080
APP_TIMEZONE=America/Sao_Paulo
```

PostgreSQL:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=foodrescue
DB_USERNAME=foodrescue
DB_PASSWORD=foodrescue
```

Laravel infrastructure:

```dotenv
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
```

Solana:

```dotenv
SOLANA_CLUSTER=devnet
SOLANA_RPC_URL=https://api.devnet.solana.com
SOLANA_COMMITMENT=confirmed

SOLANA_PROGRAM_ID=
SOLANA_TOKEN_MINT=
SOLANA_PROTOCOL_AUTHORITY=
SOLANA_PROTOCOL_TREASURY=
```

Wallet verification:

```dotenv
WALLET_CHALLENGE_TTL_MINUTES=5
```

Timeout settings are application/database configuration, not `.env`.

---

## 25. Explicitly Out of Scope for MVP

Do not add unless required to fix an implementation defect:

- AI classification;
- AI logistics;
- production forecasting;
- photos;
- lot splitting;
- Anchor;
- Redis;
- email flow;
- Mailpit;
- local validator;
- auction/sealed bids;
- bid bonds;
- rescue funding pool;
- crowdfunding;
- PIX;
- BRL/USDC conversion;
- KYC integration;
- NF-e;
- GPS/IoT;
- insurance;
- credit;
- DAO;
- own token;
- advanced disputes/arbitration;
- automated route optimization;
- advanced Sybil/reputation algorithms.

---

## 26. What Must Be Verified Now

The implementation was built incrementally and must now be reviewed as one coherent application.

The IDE/Codex verification should validate:

### Laravel
- migrations execute cleanly from empty database;
- migration rollback works;
- no conflicting or obsolete migrations;
- models and relationships are correct;
- enums/state values are consistent;
- routes resolve correctly;
- policies/middleware/permissions match roles;
- Form Requests validate correctly;
- private fields do not leak through Resources;
- services use transactions correctly;
- `lockForUpdate` protects reservation races;
- scheduler commands/jobs are registered;
- timeout behavior is correct;
- database queue/cache/session configuration works;
- PHPUnit tests pass;
- Pint/static analysis passes if configured.

### State machines
Check every valid and invalid transition for:
- SurplusLot;
- Trade;
- ShippingRequest;
- ShippingOffer;
- blockchain lifecycle;
- cancellation;
- donation.

### Financial rules
Verify:
- 2% protocol fee;
- producer receives 98%;
- freight goes 100% to carrier;
- donation has zero product fee;
- NGO-managed donation has zero escrow;
- funded cancellation refunds 100% to buyer;
- expiration does not leave reserved lots/carriers stuck.

### Wallet security
Verify:
- Ed25519 challenge/signature;
- replay prevention;
- expiry;
- one-time nonce;
- wallet change invalidates old verification;
- unverified wallets cannot perform financial operations.

### Solana
Compile and test native Rust program:
- `cargo build-sbf`;
- instruction decoding;
- PDA derivations;
- account ownership;
- signer validation;
- SPL Token accounts/mint;
- `initialize_protocol`;
- `initialize_trade`;
- `fund_trade`;
- `settle_trade`;
- `cancel_trade`;
- `create_rescue_proof`.

Validate that Rust state layout exactly matches Laravel decoding logic.

### RPC
Verify Laravel RPC logic:
- confirmation level;
- transaction failure handling;
- malformed RPC responses;
- duplicate signatures;
- wrong program;
- wrong PDA;
- wrong mint;
- wrong participants;
- wrong amount;
- unexpected on-chain state.

### PostgreSQL
Test with PostgreSQL 17, not only SQLite.

Check:
- foreign keys;
- unique constraints;
- indexes;
- numeric precision;
- JSON columns;
- concurrent reservation behavior.

### End-to-end
At minimum test:

Commercial:
```text
register producer
→ verify wallet
→ register buyer
→ verify wallet
→ producer creates surplus
→ buyer buys / offer accepted
→ shipping quotation
→ carrier selection or buyer-managed
→ WAITING_PAYMENT
→ initialize on-chain
→ fund
→ pickup
→ transit
→ delivered
→ settle
→ COMPLETED
→ rating
→ dashboard
```

Cancellation:
```text
trade
→ initialize/fund
→ mutual cancellation
→ refund
→ CANCELLED
```

Donation:
```text
producer creates donation-eligible lot
→ NGO accepts
→ logistics
→ delivery
→ settlement if carrier
→ Proof of Rescue
→ DONATED / COMPLETED
→ rating
→ impact dashboard
```

---

## 27. Review Philosophy

During the validation phase:

1. Fix correctness and security defects.
2. Do not redesign the product without a concrete reason.
3. Preserve the defined MVP scope.
4. Prefer simple Laravel-native patterns.
5. Keep blockchain custody in user wallets.
6. Keep full marketplace metadata off-chain.
7. Do not silently change business rules.
8. Document every material correction.
9. Add regression tests for every discovered bug.
10. Prefer PostgreSQL/Devnet behavior over assumptions made from SQLite or mocked RPCs.



## Consolidação técnica de 7 de setembro de 2026

O [VALIDATION_REPORT.md](VALIDATION_REPORT.md) registra testes reais nos containers, correções, diferenças entre regras conceituais e estados persistidos e decisões pendentes. Os documentos específicos de blockchain/cancelamento e IMPLEMENTATION_STATUS foram atualizados para refletir o código existente.

O timeout reabre somente lotes válidos e sem preparação/conta on-chain pendente; preparar uma instrução preserva a reserva até reconciliação segura. Compras comerciais de valor zero são rejeitadas porque o escrow comercial exige valor positivo; doações usam o fluxo próprio. Refund retorna o saldo integral da vault, inclusive depósitos extras. Valores são tratados em decimal exato no PostgreSQL/PHP e inteiros no programa. Essas correções não adicionam os módulos fora do escopo do MVP.
