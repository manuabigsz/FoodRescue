# FoodRescue — Cancelamento e refund

## Regras do MVP

Há dois fluxos.

### Antes de existir estado on-chain

Buyer ou producer podem cancelar enquanto o trade estiver em:

- `RESERVED`
- `SHIPPING_QUOTATION`
- `CARRIER_SELECTED`
- `BUYER_MANAGED`
- `WAITING_PAYMENT`

desde que não exista conta em `blockchain_trade_accounts` nem snapshot em `trades.blockchain_preparation`.

Endpoint:

```http
POST /api/v1/trades/{trade}/cancel
```

Se o buyer cancela, o lote volta para `OPEN` se ainda estiver válido; caso contrário vai para `EXPIRED`.
Se o producer cancela, o lote vai para `CANCELLED`.

### Depois de initialize_trade

O cancelamento deve passar pela Solana:

```http
POST /api/v1/trades/{trade}/blockchain/cancellation/prepare
POST /api/v1/trades/{trade}/blockchain/cancellation/confirm
```

Permitido apenas em `WAITING_PAYMENT` ou `FUNDED`, sempre antes de `READY_FOR_PICKUP`.

Em estado `INITIALIZED`, buyer ou producer podem assinar `cancel_trade`.

Em estado `FUNDED`, **buyer e producer precisam assinar a mesma transação**. O refund financiado nunca é unilateral.

- `INITIALIZED`: devolve eventual saldo enviado diretamente à vault e marca `CANCELLED`; buyer OU producer assina.
- `FUNDED`: devolve 100% do saldo da vault, que deve ser pelo menos `product_amount + shipping_amount`; buyer E producer assinam. Depósitos extras também são devolvidos.
- Não existe protocol fee em cancelamento.
- Não existe pagamento de frete em cancelamento.
- Após confirmação, a vault deve estar com saldo zero.

## Instrução on-chain

Tag:

```text
4 = cancel_trade
```

Accounts:

1. buyer (signer obrigatório se FUNDED)
2. producer (signer obrigatório se FUNDED)
3. trade PDA
4. vault token account
5. buyer token account
6. mint
7. SPL Token Program

O programa valida que o signer é o buyer ou producer original e que a token account de refund pertence ao buyer e usa a mint do trade.

## Auditoria

`trades` registra:

- `cancelled_by_id`
- `cancellation_reason`
- `cancelled_at`

`blockchain_trade_accounts` registra:

- `cancelled_at`
- `refunded_at`

`blockchain_transactions.type` registra `cancel_trade`.

O backend exige estado CANCELLED, vault vazia, signers e instrução corretos. O valor auditado vem do TransferChecked interno de cancelamento retornado pela RPC e é confrontado com o saldo anterior; assim inclui tokens recebidos antes da instrução, inclusive dentro da mesma transação.

A restrição “antes da coleta” também é validada on-chain para trades com escrow: `cancel_trade` só aceita `INITIALIZED` ou `FUNDED`; depois da transição para `READY_FOR_PICKUP`, o refund é rejeitado pelo programa. A máquina on-chain registra `READY_FOR_PICKUP`, `IN_TRANSIT` e `DELIVERED`, e settlement só aceita `DELIVERED`. Doações NGO-managed sem escrow não possuem TradeState financeiro e continuam protegidas pelas regras off-chain.
