# Front-end FoodRescue

Interface responsiva integrada ao Laravel/Vite, consumindo a API versionada em `/api/v1`.

## Telas

As rotas usam hash, evitando configuração de fallback de SPA no Nginx.

| Rota | Tela | Acesso |
|---|---|---|
| `#/` | Landing e explicação do fluxo | pública |
| `#/catalogo` | Catálogo de excedentes com busca, filtro, ordenação e paginação | autenticada |
| `#/publicar` | Cadastro de excedente | produtor |
| `#/meus-lotes` | Histórico dos próprios lotes, propostas recebidas e edição | produtor |
| `#/acompanhamento` | Operações do usuário, timeline e ações do estado atual | autenticada |
| `#/fretes` | Rotas abertas para cotação | transportadora |
| `#/dashboard` | Painel do papel, com métricas apuradas pela API | autenticada |
| `#/reputacao` | Avaliações recebidas (`?user=ID` para ver a de outro participante) | autenticada |
| `#/perfil` | Dados da conta, senha, carteira e encerramento de sessões | autenticada |
| `#/admin` | Usuários, catálogo, prazos e impacto (abas em `?tab=`) | administrador |
| `#/doacoes` | Fluxo de doação e Proof of Rescue | pública |
| `#/rede` | Programa, mint e ProtocolConfig na Solana | pública |

Login e cadastro não têm rota própria: são um modal aberto pelo cabeçalho.

## Organização do código

`resources/js/app.js` é só o bootstrap; o roteamento fica em `router.js`.

```
core/    config · state · labels · format · api · session · ui
auth/    modal
pages/   uma tela por arquivo (+ tracking-actions para os painéis de ação)
```

Regra de dependência: `core/` não importa de `pages/` nem do `app.js`.

## Configuração

Copie as variáveis de `.env.frontend.example` para o `.env` da aplicação:

```dotenv
VITE_API_URL=/api/v1
VITE_SOLANA_NETWORK=devnet
VITE_SOLANA_PROGRAM_ID=ENDERECO_PUBLICO_DO_PROGRAMA
VITE_FRUSD_MINT=ENDERECO_PUBLICO_DO_MINT
VITE_SOLSCAN_URL=https://solscan.io
```

Recompile depois de alterar variáveis `VITE_*`:

```sh
npm install
npm run build
```

## Sessão

O cadastro chama `POST /auth/wallet/challenge`, pede à carteira uma assinatura Ed25519 da mensagem recebida e envia a assinatura em Base64 para `POST /auth/register`. A chave privada nunca é acessada.

A sessão usa e-mail e senha em `POST /auth/login`. O token Sanctum fica em `sessionStorage` e é descartado quando a aba é encerrada. No carregamento, `GET /auth/me` revalida o token guardado; um `expires_at` vencido descarta a sessão antes de qualquer requisição, e qualquer resposta 401 a encerra.

## Integração

Todas as telas consomem a API. A única exceção é o catálogo: quando a listagem falha — inclusive para visitante anônimo, já que `GET /surplus` exige autenticação —, ele exibe lotes de demonstração **com aviso visível**, e as ações de compra e doação ficam bloqueadas nesse modo.

A etapa `waiting_payment → funded` depende de assinatura de transação Solana, que não é feita no navegador. A tela de pagamento mostra os dados devolvidos por `POST /trades/{trade}/blockchain/prepare` e permite copiá-los; a assinatura e a confirmação são feitas pelos scripts em `../../solana/`.

## Testes

Vitest com jsdom, em `tests/js/`. O shell da página é lido do próprio `welcome.blade.php`, de modo que a remoção de um elemento esperado pelo JS quebra os testes.

```sh
npm test          # execução única
npm run test:watch
```

`composer test` roda as duas suítes; `composer test:php` roda apenas o backend.

Imagem agrícola: mk. s / Unsplash (`photo-1632776350300-11016768b521`).
