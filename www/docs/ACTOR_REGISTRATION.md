# FoodRescue — cadastro dos atores

O FoodRescue mantém autenticação/identidade em `users` e dados operacionais em uma tabela de perfil específica por ator.

Não há KYC completo no MVP. `document_number` e `registration_number` são identificadores declarados pelo usuário e não são validados contra serviços externos nesta fase.

## Endpoints

O cadastro usa prova de posse da wallet em duas etapas:

```http
POST /api/v1/auth/wallet/challenge
POST /api/v1/auth/register
```

Primeiro envie `wallet_address` ao endpoint de challenge. A resposta contém `id`, `message` e `expires_at`. O frontend deve solicitar à wallet Solana que assine exatamente `message` com `signMessage`. A assinatura deve ser enviada em Base64 no cadastro.

Campos base para todos:

```json
{
  "name": "Nome do responsável",
  "email": "user@example.com",
  "solana_wallet_address": "<PUBLIC_KEY_SOLANA>",
  "wallet_challenge_id": 123,
  "wallet_signature": "<ASSINATURA_ED25519_BASE64>",
  "password": "senha-segura",
  "password_confirmation": "senha-segura",
  "role": "producer|buyer|carrier|ngo",
  "profile": {}
}
```

## Producer

Campos de `profile`:

- `producer_type`: `individual`, `company` ou `cooperative`
- `organization_name`: obrigatório para empresa/cooperativa; opcional para individual
- `farm_name`: opcional
- `document_number`
- `phone`
- `country`
- `state`
- `city`
- `address_line`
- `postal_code`: opcional

Exemplo:

```json
{
  "name": "Maria Silva",
  "email": "maria@example.com",
  "solana_wallet_address": "<PUBLIC_KEY_SOLANA>",
  "wallet_challenge_id": 123,
  "wallet_signature": "<ASSINATURA_ED25519_BASE64>",
  "password": "uma-senha-segura",
  "password_confirmation": "uma-senha-segura",
  "role": "producer",
  "profile": {
    "producer_type": "cooperative",
    "organization_name": "Cooperativa Vale Verde",
    "farm_name": "Unidade Norte",
    "document_number": "DOC-123",
    "phone": "+55 11 99999-0000",
    "country": "Brazil",
    "state": "SP",
    "city": "Campinas",
    "address_line": "Rodovia Exemplo, km 10",
    "postal_code": "13000-000"
  }
}
```

## Buyer

Campos de `profile`:

- `buyer_type`: `individual` ou `company`
- `organization_name`: obrigatório para empresa; opcional para individual
- `document_number`
- `phone`
- `country`
- `state`
- `city`
- `address_line`
- `postal_code`: opcional

## Carrier

Campos de `profile`:

- `company_name`
- `document_number`
- `phone`
- `contact_name`
- `country`
- `state`
- `city`
- `address_line`
- `postal_code`: opcional
- `service_regions`: lista de regiões atendidas
- `vehicle_types`: lista opcional, por exemplo `truck`, `van`, `refrigerated_truck`
- `max_capacity_kg`: opcional

## NGO

Campos de `profile`:

- `organization_name`
- `registration_number`
- `phone`
- `contact_name`
- `country`
- `state`
- `city`
- `address_line`
- `postal_code`: opcional
- `description`: opcional

## Persistência

```text
users
├── producer_profiles
├── buyer_profiles
├── carrier_profiles
└── ngo_profiles
```

Cada usuário público possui exatamente um papel principal e um perfil correspondente. Admin não possui perfil de ator do marketplace.

## Resposta

O perfil correspondente é retornado junto ao usuário em:

- cadastro;
- login;
- `GET /api/v1/auth/me`;
- consultas administrativas de usuários.

Exemplo simplificado:

```json
{
  "data": {
    "id": 10,
    "name": "Maria Silva",
    "email": "maria@example.com",
    "status": "active",
    "roles": ["producer"],
    "profile": {
      "producer_type": "cooperative",
      "organization_name": "Cooperativa Vale Verde",
      "farm_name": "Unidade Norte",
      "document_number": "DOC-123",
      "phone": "+55 11 99999-0000",
      "country": "Brazil",
      "state": "SP",
      "city": "Campinas",
      "address_line": "Rodovia Exemplo, km 10",
      "postal_code": "13000-000"
    }
  }
}
```

Senha, IDs internos do perfil e timestamps internos das tabelas de perfil não são expostos nesse objeto.

## Wallet Solana

Os quatro atores públicos informam `solana_wallet_address`, mas o endereço só é aceito depois de uma prova de posse Ed25519.

Fluxo:

```text
wallet -> challenge -> signMessage -> register/verify -> solana_wallet_verified_at
```

O challenge é de uso único e expira por padrão em 5 minutos (`WALLET_CHALLENGE_TTL_MINUTES`).

Contas já existentes ou usuários que precisem trocar a wallet usam:

```http
POST /api/v1/auth/wallet/change/challenge
POST /api/v1/auth/wallet/change/verify
```

A wallet não pode mais ser alterada pelo `PATCH /api/v1/auth/me`; toda troca exige um novo challenge e uma nova assinatura.

Somente a public key e o timestamp de verificação são persistidos. Seed phrase e private key nunca devem ser enviadas para a API.
