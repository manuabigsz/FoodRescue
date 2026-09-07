# FoodRescue

Backend API-first do **FoodRescue**, marketplace para dar destino comercial ou social a excedentes agrícolas reais antes que se tornem desperdício.

## Stack

- PHP 8.5 / Laravel 13
- PostgreSQL 17
- Laravel Sanctum
- Spatie Permission
- Queue, cache e session em PostgreSQL no MVP
- Solana Devnet para a camada on-chain
- Programa Solana em Rust nativo, sem Anchor

## Atores

- Admin
- Producer
- Buyer
- Carrier
- NGO / Social Institution

## API

A API é versionada em `/api/v1`.

O cadastro público usa um endpoint único:

```http
POST /api/v1/auth/register
```

O campo `role` determina a estrutura exigida em `profile`. Consulte `docs/ACTOR_REGISTRATION.md`.

## Escopo atual do backend

Consulte `docs/IMPLEMENTATION_STATUS.md` para o estado de implementação e próximos passos.
