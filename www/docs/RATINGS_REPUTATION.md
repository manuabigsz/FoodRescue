# Ratings e reputação — FoodRescue

## Objetivo

Permitir reputação básica entre participantes de operações concluídas, sem armazenar médias redundantes no cadastro do usuário.

## Regras

- avaliação somente após `trade.status = completed`;
- nota inteira entre 1 e 5;
- comentário opcional de até 1000 caracteres;
- um usuário não pode avaliar a si mesmo;
- uma única avaliação para a combinação `trade + reviewer + target`;
- somente relações diretamente envolvidas na operação podem ser avaliadas.

Relações comerciais:

- Buyer → Producer;
- Producer → Buyer;
- Buyer → Carrier;
- Carrier → Buyer.

Em doações, o recipient é a NGO:

- NGO → Producer;
- Producer → NGO;
- NGO → Carrier;
- Carrier → NGO.

Não há avaliação direta Producer ↔ Carrier neste MVP.

## Endpoints

### Criar avaliação

`POST /api/v1/trades/{trade}/ratings`

```json
{
  "target_user_id": 12,
  "rating": 5,
  "comment": "Entrega e produto conforme combinado."
}
```

### Histórico recebido

`GET /api/v1/users/{user}/ratings`

Retorna paginação de 20 avaliações por página.

### Reputação agregada

`GET /api/v1/users/{user}/reputation`

Exemplo:

```json
{
  "data": {
    "user_id": 12,
    "average": 4.75,
    "count": 8
  }
}
```

Sem avaliações, `average` é `null` e `count` é `0`.

## Persistência

Tabela `ratings`:

- `trade_id`
- `reviewer_id`
- `target_user_id`
- `rating`
- `comment`
- timestamps

Constraint única: `trade_id + reviewer_id + target_user_id`.
