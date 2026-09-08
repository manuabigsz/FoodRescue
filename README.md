# FoodRescue

Marketplace que dá destino comercial ou social a excedentes agrícolas antes que virem desperdício. O pagamento fica em custódia num programa Solana e só é liberado quando a entrega é confirmada — nenhuma das partes precisa confiar na outra.

## Estrutura

```
www/      aplicação Laravel: API em /api/v1 e front-end
solana/   programa Solana em Rust + scripts que assinam as transações
```

São dois projetos independentes. O Laravel **prepara** as instruções on-chain, mas nunca assina: a assinatura vem da carteira do usuário ou dos scripts em `solana/`.

## Atores

Produtor publica o excedente · Comprador compra ou ONG recebe como doação · Transportadora cota o frete · Administrador cuida do catálogo e dos prazos.

## Como rodar

Requisitos: PHP 8.3+, Composer, Node 20+, PostgreSQL 17 (ou Docker).

**1. Banco**

```bash
docker run -d --name food-rescue-postgres -p 5432:5432 \
  -e POSTGRES_DB=food_rescue -e POSTGRES_USER=agro -e POSTGRES_PASSWORD=agro \
  postgres:17-alpine
```

**2. Aplicação**

```bash
cd www
cp .env.example .env          # ajuste DB_HOST para 127.0.0.1 fora do Docker
composer install
npm install
php artisan key:generate
php artisan migrate --seed
```

O seed cria papéis, permissões, o catálogo de produtos e o administrador definido em `INITIAL_ADMIN_EMAIL` / `INITIAL_ADMIN_PASSWORD` no `.env`. A senha precisa ter no mínimo 15 caracteres.

**3. Subir**

```bash
php artisan serve --port=8080   # em um terminal
npm run dev                     # em outro
```

Acesse `http://localhost:8080`.

## Testes

```bash
composer test       # backend (PHPUnit) + front-end (Vitest)
composer test:php   # só o backend
npm test            # só o front-end
```

Os testes de backend exigem um banco `food_rescue_api_test` no mesmo PostgreSQL:

```bash
docker exec food-rescue-postgres psql -U agro -d postgres -c "CREATE DATABASE food_rescue_api_test OWNER agro"
```

## Fluxo de uma operação

```
reserved → logística → waiting_payment → funded → ready_for_pickup
        → in_transit → delivered → [proof_pending] → completed
```

Da reserva até `waiting_payment` tudo acontece pela interface. As etapas seguintes exigem transação assinada na Solana; a tela mostra quem assina e qual comando rodar:

```bash
cd solana && npm install
npm run pagar       # cria a custódia e deposita o FRUSD
npm run entregar    # coleta e entrega
npm run liquidar    # libera produto, frete e taxa
```

Esses comandos descobrem sozinhos a operação no estado certo. Os endereços do ambiente ficam em `solana/devnet.config.json`.

> O ambiente é a **Devnet**. O token FRUSD é de teste e não tem valor monetário.

O backend confere cada transação na RPC antes de mudar de estado, então o PHP precisa de um bundle de certificados válido (`curl.cainfo` no `php.ini`) — sem isso toda confirmação on-chain falha.

## Documentação

| Assunto | Onde |
|---|---|
| Telas, rotas e organização do front | [www/docs/FRONTEND.md](www/docs/FRONTEND.md) |
| Cadastro por tipo de ator | [www/docs/ACTOR_REGISTRATION.md](www/docs/ACTOR_REGISTRATION.md) |
| Custódia e pagamento on-chain | [www/docs/BLOCKCHAIN_PAYMENTS.md](www/docs/BLOCKCHAIN_PAYMENTS.md) |
| Doações e Proof of Rescue | [www/docs/DONATIONS_RESCUE_PROOF.md](www/docs/DONATIONS_RESCUE_PROOF.md) |
| Estado da implementação | [www/docs/IMPLEMENTATION_STATUS.md](www/docs/IMPLEMENTATION_STATUS.md) |
| Programa Solana e instruções | [solana/README.md](solana/README.md) |
| Endereços e checkpoints da Devnet | [solana/devnet-e2e-state.md](solana/devnet-e2e-state.md) |
