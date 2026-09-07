# FoodRescue — Dashboards e métricas de impacto

Os dashboards são derivados das tabelas transacionais. Não há tabela de métricas materializadas nesta fase do MVP.

## Endpoints por ator

```http
GET /api/v1/dashboard/producer
GET /api/v1/dashboard/buyer
GET /api/v1/dashboard/carrier
GET /api/v1/dashboard/ngo
```

Cada endpoint exige que o usuário autenticado possua o papel correspondente.

### Producer

- lotes ativos (`OPEN` ou `RESERVED`)
- operações comerciais concluídas
- doações concluídas
- receita recuperada
- quantidades vendidas/doada/destinadas por unidade
- distribuição dos trades por status
- reputação

### Buyer

- compras concluídas
- gasto com produto
- gasto com frete
- gasto total
- quantidade comprada por unidade
- distribuição dos trades por status
- reputação

### Carrier

- entregas concluídas em que sua proposta foi selecionada
- receita de frete
- quantidade transportada por unidade
- distribuição dos trades selecionados por status
- reputação

### NGO

- doações concluídas
- gasto com frete
- Proofs of Rescue confirmados
- quantidade resgatada por unidade
- distribuição dos trades de doação por status
- reputação

## Administração

```http
GET /api/v1/admin/dashboard
GET /api/v1/admin/impact
```

`/admin/dashboard` resume operação, usuários, lotes, receita recuperada, fees e frete.

`/admin/impact` concentra os KPIs de impacto do protocolo.

## Quantidades

Quantidades nunca são somadas entre unidades incompatíveis.

Exemplo:

```json
{
  "kg": "1250.000",
  "t": "8.000",
  "box": "40.000"
}
```

O MVP não converte automaticamente `box`/`unit` para massa. Uma conversão futura deverá usar dados confiáveis por produto/embalagem.

## Regras financeiras

Somente trades `COMPLETED` entram nas métricas financeiras de impacto.

- `producer_revenue_recovered`: soma de `product_amount - protocol_fee` de trades comerciais concluídos, representando o recebimento líquido do produtor.
- `protocol_fees`: soma de `protocol_fee` de trades comerciais concluídos.
- `freight_paid`: soma de `shipping_amount` de todos os trades concluídos.
- doações não geram `protocol_fee` nem receita de produto para o produtor.

## Observação de performance

No MVP, os agregados são consultados diretamente no PostgreSQL. Se o volume crescer, a evolução natural é materializar métricas periódicas ou usar uma camada analítica, sem alterar a semântica dos endpoints.
