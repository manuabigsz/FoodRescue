# Front-end FoodRescue

Interface responsiva integrada ao Laravel/Vite e preparada para a API versionada em `/api/v1`.

## Telas

- Landing page e explicação do fluxo;
- Login e cadastro com conexão de carteira Solana;
- Dashboards de produtor, comprador, transportadora e ONG;
- Catálogo de excedentes com busca, filtro e ordenação;
- Compra imediata, aceite de doação e acompanhamento;
- Doações e Proof of Rescue;
- Página da Solana Devnet com programa, mint FRUSD e links para o Solscan.

As rotas do front-end usam hash (`#/catalogo`, `#/dashboard`, `#/acompanhamento`, `#/doacoes` e `#/rede`), evitando configuração adicional no Nginx para fallback de SPA.

## Configuração

Copie as variáveis de `.env.frontend.example` para o `.env` da aplicação e preencha os endereços públicos:

```dotenv
VITE_API_URL=/api/v1
VITE_SOLANA_NETWORK=devnet
VITE_SOLANA_PROGRAM_ID=ENDERECO_PUBLICO_DO_PROGRAMA
VITE_FRUSD_MINT=ENDERECO_PUBLICO_DO_MINT
VITE_SOLSCAN_URL=https://solscan.io
```

Recompile o front-end depois de alterar variáveis `VITE_*`:

```sh
npm install
npm run build
```

## Carteira e sessão

O cadastro chama `POST /auth/wallet/challenge`, pede à carteira uma assinatura Ed25519 da mensagem recebida e envia a assinatura em Base64 para `POST /auth/register`. A chave privada nunca é acessada.

O backend atual autentica a sessão com e-mail e senha em `POST /auth/login`. O endereço da carteira continua visível e verificado no perfil. O token Sanctum fica em `sessionStorage`, sendo descartado quando a aba é encerrada.

## Integração

Quando a API estiver indisponível, o catálogo e os dashboards mostram dados de demonstração para permitir avaliação visual. Operações mutáveis — compra e aceite de doação — exigem uma sessão real e usam os endpoints existentes.

Imagem agrícola: mk. s / Unsplash (`photo-1632776350300-11016768b521`).
