# FoodRescue — Logística do MVP

## Fluxo implementado

```text
Trade RESERVED
   ↓
Buyer informa destino
   ↓
ShippingRequest QUOTING (4h)
   ↓
Carrier envia ShippingOffer
   ↓
Buyer seleciona oferta ───────────────┐
   │                                  │
   └─ ou escolhe BUYER_MANAGED        │
   └─ ou timeout de 4h → BUYER_MANAGED│
                                      ↓
                              WAITING_PAYMENT (15 min)
                                      ↓
                         próxima etapa: funding Solana
```

Para trades com escrow, o fluxo on-chain prossegue por `FUNDED → READY_FOR_PICKUP → IN_TRANSIT → DELIVERED`. Cada transição exige uma transação assinada pelo ator correspondente; settlement só pode ocorrer depois de `DELIVERED`. Doações NGO-managed sem escrow permanecem no fluxo operacional off-chain.

`PRODUCER_DELIVERY` não é uma modalidade disponível no MVP: o produtor pode aceitar `BUYER_MANAGED` ou `THIRD_PARTY_CARRIER`, e o timeout de cotação sempre faz fallback para `BUYER_MANAGED`. O valor legado `producer_delivery` permanece reconhecível apenas para compatibilidade de dados, mas é rejeitado na criação e atualização de lotes.

## Rotas

- `POST /api/v1/trades/{trade}/shipping`
- `GET /api/v1/trades/{trade}/shipping`
- `GET /api/v1/shipping-requests`
- `POST /api/v1/shipping-requests/{shippingRequest}/offers`
- `GET /api/v1/trades/{trade}/shipping-offers`
- `POST /api/v1/trades/{trade}/shipping-offers/{shippingOffer}/select`
- `POST /api/v1/trades/{trade}/buyer-managed`
- `POST /api/v1/trades/{trade}/delivery/ready-for-pickup/prepare` + confirmação assinada em `/delivery/ready-for-pickup`
- `POST /api/v1/trades/{trade}/delivery/pickup/prepare` + confirmação assinada em `/delivery/pickup`
- `POST /api/v1/trades/{trade}/delivery/delivered/prepare` + confirmação assinada em `/delivery/delivered`

## Timeouts

- cotação: 240 minutos
- pagamento: 15 minutos

O scheduler executa a cada minuto:

- `foodrescue:expire-shipping-quotations`
- `foodrescue:expire-unfunded-trades`

Ao expirar uma cotação, o trade segue para transporte gerenciado pelo comprador e inicia a janela de pagamento.

Ao expirar um trade não financiado sem preparação blockchain, o trade vira `EXPIRED`, o lote volta para `OPEN` e qualquer transportadora selecionada é liberada imediatamente. Se uma instrução blockchain foi preparada, o scheduler aguarda `BLOCKCHAIN_RECONCILIATION_GRACE_MINUTES` (30 minutos por padrão) e consulta a RPC por um `TradeState` daquele ID. O lote só é liberado quando a resposta confirma ausência de estado; conta encontrada, resposta inválida ou indisponibilidade da RPC preserva a reserva.

## Observação de unidade

`shipping_requests` guarda a `quantity` e a `unit` originais do lote. Não há conversão artificial para quilogramas quando o excedente estiver cadastrado como `box` ou `unit`.
