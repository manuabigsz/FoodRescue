# FoodRescue — relatório de validação

Data: 7 de setembro de 2026. Workspace: `/Volumes/Work/Docker/Volumes/FoodRescue`.

## Resultado

A aplicação está executando em **http://localhost:8080**, usando os containers existentes. Resultado final: **332 testes Laravel aprovados, 1.134 asserções, 34,23 s**; **7 testes Rust aprovados**; **Pint aprovado em 206 arquivos**; **Clippy sem warnings**; **cargo build-sbf concluído**, artefato `solana/target/deploy/foodrescue.so` (114 KiB aproximadamente).

Quatro E2E da API foram executados: comercial com carrier, cancelamento/refund bilateral, doação com carrier e doação com transporte pela NGO. Cadastro, assinaturas Ed25519, autenticação, banco, API e regras são reais; **a RPC Solana é simulada nesses testes**. O smoke HTTP também passou contra o servidor Nginx/PHP em execução. Não houve deploy, assinatura de transação real, transferência de tokens ou uso de validator local.

A revisão não certifica prontidão para fundos reais. A RPC configurada permanece uma fronteira de confiança operacional, e as limitações de reorg/provedor estão detalhadas abaixo.

## Ambiente e isolamento

| Serviço | Container / versão observada | Resultado |
|---|---|---|
| Laravel/PHP | `food-rescue`, PHP 8.5.10, Composer 2.10.3 | Healthy, HTTP funcional |
| Banco | `food-rescue-postgres`, PostgreSQL **17.11** | Healthy |
| Queue | `food-rescue-queue`, conexão database | Healthy; job real processado em teste |
| Scheduler | `food-rescue-scheduler` | Healthy; dois comandos registrados a cada minuto |
| Frontend build | `food-rescue-node`, Node 24 | `npm run build` aprovado |
| Solana | `food-rescue-solana`, Rust/Cargo 1.98.1, CLI 4.2.2 | Testes e compilação aprovados |
| SBF | cargo-build-sbf 4.1.0, platform-tools 1.54, Rust embarcado 1.89.0 | Build aprovado |

Os testes usam exclusivamente `food_rescue_api_test`, via pgsql. `phpunit.xml` força ambiente/banco e `Tests\TestCase` recusa configuração em cache, SQLite ou outro banco. O teste de concorrência usa dois processos PHP e duas sessões PostgreSQL independentes. O banco de aplicação `food_rescue` recebeu migrations incrementais e seed; **não recebeu migrate:fresh**.

O seeder exige credenciais iniciais válidas. Foi configurado um administrador local `admin@foodrescue.test`, com senha aleatória forte armazenada somente em `www/.env`, em `INITIAL_ADMIN_PASSWORD`. A senha não foi incluída neste relatório. Não foram coletadas chaves privadas nem seed phrases; as chaves Ed25519 dos testes são efêmeras.

`compose.validation.yaml`, na raiz do workspace, corrige o healthcheck dos workers: eles executam PHP como PID 1 e não possuem servidor HTTP. É um complemento ao Compose existente, preservando o arquivo externo. O healthcheck confirma a presença do processo; a conectividade e o processamento foram verificados separadamente no teste de infraestrutura.

## Comandos executados

Os comandos PHP/Composer foram executados por `docker exec food-rescue`; Node por `docker exec food-rescue-node`; Rust por `docker exec food-rescue-solana`. Não foram instalados runtimes no host.

| Comando / operação | Resultado |
|---|---|
| `php -v`, `composer --version` | Disponíveis no container |
| `composer install --no-interaction` | Dependências instaladas; inicialmente lock desatualizado |
| `composer update --lock --no-install` | Lock reconciliado, sem atualizar versões dos pacotes |
| `composer validate --strict` | Aprovado |
| `php artisan boost:install --guidelines --no-interaction` | Boost já instalado; primeira geração encontrou diretório de views ausente, posteriormente corrigido |
| `php artisan optimize:clear` | Aprovado após criar diretórios de storage ausentes |
| `php artisan route:list` | Rotas carregadas |
| `php artisan config:show queue` | Configuração de fila inspecionada, sem expor credenciais |
| `php artisan route:cache`, `route:clear`, `config:cache` | Aprovados; caches limpos ao terminar |
| `php artisan migrate:fresh --seed --force` no banco de teste | Aprovado com PostgreSQL 17 |
| Rollback integral das migrations no banco de teste recém-criado | Aprovado |
| `php artisan migrate --seed --force` e `php artisan db:seed --force` repetido no banco de teste | Aprovados |
| `php artisan migrate --force`, `db:seed --force`, `migrate:status` no banco da aplicação | Aplicados; todas as migrations executadas |
| `php artisan test --compact --colors=never` | **332 passed, 1134 assertions** |
| `vendor/bin/pint`, seguido de `vendor/bin/pint --test` | **206 files, PASS** |
| `php artisan schedule:list` | Dois comandos a cada minuto |
| Worker `queue:work database --queue=validation --once --tries=1` | Job de teste processado; cache, locks e sessões database verificados |
| `php tests/Support/http_smoke.php` | `/` e `/up` 200; `/auth/me` sem token 401; login, me, admin/dashboard, admin/impact e logout funcionais |
| `npm run build` | Aprovado; aviso opcional do Fontaine sobre métricas de fonte |
| `rustup component add rustfmt clippy` | Componentes ausentes instalados no container Solana |
| `cargo fmt --check` | Aprovado |
| `cargo test` | **7 passed** |
| `cargo clippy --all-targets -- -D warnings` | Aprovado |
| `cargo build-sbf` | Aprovado |
| `cargo run --example export_layout` | Fixtures binárias geradas pelo serializer Rust |

Não havia configuração de PHPStan, Larastan ou Psalm para executar. Pint e compilação não substituem análise estática completa.

Reprodução da suíte principal a partir do workspace:

```bash
docker exec food-rescue php artisan config:clear
docker exec food-rescue php artisan test --compact --colors=never
docker exec food-rescue vendor/bin/pint --test
docker exec food-rescue-solana bash -lc 'cargo fmt --check && cargo test && cargo clippy --all-targets -- -D warnings && cargo build-sbf'
docker exec food-rescue-node npm run build
```

Para recriar somente os workers com o healthcheck corrigido:

```bash
docker compose -f /Volumes/Work/Docker/Compose/FoodRescue/compose.yaml -f /Volumes/Work/Docker/Volumes/FoodRescue/compose.validation.yaml up -d --no-deps queue scheduler
```

Comando destrutivo **somente para o banco de teste**, já existente e explicitamente selecionado:

```bash
docker exec -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_DATABASE=food_rescue_api_test -e DB_URL= -e CACHE_STORE=array food-rescue php artisan migrate:fresh --seed --force
```

## Erros encontrados e correções

A execução inicial tinha 99 testes: 75 passavam e 24 falhavam. As falhas misturavam bugs reais, falta de diretórios/extensões e fixtures incompatíveis com o contrato HTTP.

| Problema | Correção e evidência |
|---|---|
| BCMath ausente; uso de float perdia casas decimais em totais | Brick Math declarado como dependência direta; cálculos exatos em serviços/resources/dashboards. Regressão próxima do limite numeric preserva seis casas |
| Middleware `permission` não registrado | Alias Spatie em `bootstrap/app.php`; testes de administração/permissões passam |
| Colunas ambíguas em joins dos dashboards | `trades.status`, `trades.producer_id`, `trades.id` qualificados; testes por papel e E2E passam |
| Filtro max_price dependia indevidamente de min_price | Validação aceita limite superior isolado; regressão HTTP |
| Valores com precisão maior que o banco eram arredondados | Requests limitam quantidade a três e moeda a seis casas, com limites numeric; regressão HTTP |
| Compra comercial zero criava fluxo financeiro inexequível | Rejeição atômica de produto comercial zero; doação continua pelo fluxo próprio |
| Assinaturas de transação validadas como assinatura Base64 de mensagem | Regra Base58/64 bytes específica para transações; assinatura Ed25519 de mensagem permanece Base64 |
| RPC aceitava evidência incompleta de execução | Verificador central exige status/transaction sem erro explícito, slots iguais, signers, programa e PDA na mesma instrução de tag/tamanho corretos, e rejeita replay conhecido |
| RPC malformed ou timeout não tinham cobertura adequada | Casos de null, falta de err, transação ausente/falha, slot/decimals inválidos, instrução/accounts malformados e timeout |
| Funding não reportado podia coexistir com timeout/revenda | Snapshot da preparação sob lock; reserva retida se existe preparação ou conta on-chain; confirmação tardia preserva prazo e wallets originais |
| Releitura das wallets/termos mutáveis alterava confirmação | Snapshot imutável consumido pelos passos financeiros; wallet do pagador precisa estar verificada |
| Troca direta de wallet conservava flag de verificação | Hook do model invalida verificação sem novo timestamp explícito; regressão |
| Challenge expirava no horário errado sob America/Sao_Paulo | Migration para timestamptz e serialização com offset; challenge rejeitado após seis minutos |
| Reserva dependia somente do lock de aplicação | Índice PostgreSQL parcial impede dois trades não cancelados/expirados por lote; concorrência real retorna 201 e 409 |
| Relação lote/trade devolvia tentativa histórica | `latestOfMany` para trade atual e relação `trades` para histórico; teste de revenda após expiração |
| Timeout/cancelamento reabria lote perecido | Lote vencido vai para EXPIRED; regressões preservam reserva quando pode haver escrow |
| Lock de seleção/timeout de frete tinha ordem divergente | Ordem trade → shipping request e rechecagem de estado/prazo sob lock |
| Lock de aceite competia com compra em ordem divergente | Aceite bloqueia lote antes de oferta, consistente com buy-now |
| Lotes/ofertas vencidos permaneciam abertos/pendentes | Scheduler expira lotes OPEN e ofertas pendentes; proposta vencida não bloqueia nova oferta do mesmo buyer/carrier |
| FK da oferta de frete selecionada e CHECK de rating ausentes | Migration incremental adiciona FK e CHECK 1–5 / sem autoavaliação |
| Multiplicação de fee Rust podia exceder u64 antes da divisão | Intermediário u128; teste inclui u64::MAX e arredondamento por base unit |
| Owners SPL, contas com payout zero e limites nativos insuficientemente verificados | Guards para owners/mint/destinatários, atores distintos, hash não zero e deadline inclusivo; testes nativos |
| Depósito extra na vault bloqueava refund por saldo exato | Cancelamento devolve saldo integral, inclusive extras. Native CPI capture e regressões RPC; auditoria usa TransferChecked interno, inclusive entrada na mesma transação |
| Mint de precisão diferente podia divergir da fee persistida | Preparação exige igualdade com floor(product_base_units × 200 / 10000); falha 422 antes de emitir snapshot incompatível |
| Imports Solana depreciados | Dependências diretas system-interface/sdk-ids; Clippy com warnings proibidos |
| Queue retry_after igual ao timeout do worker | retry_after 120 s para timeout de 90 s; teste de infraestrutura |
| Workers marcados unhealthy por healthcheck HTTP herdado | Override local para processo PHP, workers healthy |
| Storage de views/sessões/cache incompleto | Diretórios e arquivos .gitignore preservados; optimize:clear e HTTP passam |
| Fixtures usavam corpo JSON `[]`, guard incorreto, signatures inválidas ou status HTTP errado | Helper envia `{}` preservando headers, Sanctum nos testes, signatures Base58 válidas, 201 nos resources criados e fakes substituídos corretamente. Contratos de segurança da API preservados |

## Migrations e integridade PostgreSQL

As migrations históricas foram mantidas, sem compactação. A migration `030000` recebeu normalização de formatação. Duas migrations incrementais foram adicionadas:

- `2026_09_07_100000_harden_trade_integrity.php`: `blockchain_preparation` JSONB; índice `trades_one_active_surplus` onde status não é expired/cancelled; FK `selected_shipping_offer_id` com nullOnDelete; CHECK de rating. COMPLETED permanece no índice, pois o lote já foi consumido.
- `2026_09_07_110000_preserve_wallet_challenge_timezone.php`: converte a expiração anteriormente escrita em UTC para timestamptz, com down explícito em UTC. O model também serializa o offset; mudar somente a coluna não corrigia o problema.

Fresh/seed, rollback em base vazia e reaplicação passaram. `DatabaseIntegrityTest` também contorna os serviços e comprova SQLSTATE 23505 (unicidade), 23514 (CHECK) e 23503 (FK), além de nullOnDelete e preservação de tentativas históricas. A FK circular lógica entre shipping_requests e shipping_offers é adicionada depois de ambas as tabelas existirem e removida antes delas no rollback.

Limite de rollback histórico: `040100_allow_multiple_historical_trades_per_surplus` restaura a antiga unicidade global no down. Depois de existirem várias tentativas para um lote, esse rollback não é semanticamente possível sem tratamento de dados. Não foi apagado histórico para forçá-lo. Da mesma forma, o novo índice exige saneamento prévio se uma base externa já tiver trades ativos duplicados; a base usada passou sem intervenção desse tipo.

## Estados e transições verificadas

Os valores persistidos são minúsculos; abaixo se usa a grafia conceitual. As matrizes em `ValidationRegressionTest` e `StateMachineValidationTest` são geradas de `Enum::cases()`, incluindo estados terminais. As confirmações financeiras percorrem todos os quatro estados nativos e um estado desconhecido (255), verificando rejeição sem alteração no banco.

| Entidade/ação | Origem válida → destino | Demais estados / observações |
|---|---|---|
| Surplus buy-now / aceite de doação | OPEN → RESERVED | Outros cinco estados recusados; oferta aceita também reserva sob lock |
| Lote após conclusão | RESERVED → SOLD ou DONATED | Exercitado pelos E2E comercial/doação |
| Lote vencido | OPEN → EXPIRED | Scheduler; RESERVED com escrow possível é preservado |
| Offer aceite/rejeição | PENDING → ACCEPTED / REJECTED | Demais quatro estados recusados; ofertas concorrentes rejeitadas |
| Offer expiração | PENDING → EXPIRED | Prazo da proposta ou encerramento do lote |
| Trade criar frete | RESERVED → SHIPPING_QUOTATION | Matriz sobre os 12 estados |
| Trade transporte próprio | RESERVED ou SHIPPING_QUOTATION → WAITING_PAYMENT | NGO sem frete → FUNDED sem escrow; matrizes comerciais e E2E doação |
| ShippingOffer seleção | Request QUOTING + Offer PENDING → CARRIER_SELECTED / SELECTED | Matriz de 4 × 4; demais ofertas pendentes são rejeitadas |
| ShippingRequest timeout | QUOTING → BUYER_MANAGED | Ofertas pendentes → EXPIRED; trade inicia janela de pagamento ou doação sem escrow |
| Funding | WAITING_PAYMENT → FUNDED | Exige estado nativo FUNDED, RPC e vault compatíveis |
| Preparar retirada | FUNDED → READY_FOR_PICKUP | Producer; 12 estados × venda/doação |
| Coletar | READY_FOR_PICKUP → IN_TRANSIT | Carrier selecionado ou destinatário; 12 estados × venda/doação |
| Entregar | IN_TRANSIT → DELIVERED | Mesmo responsável logístico; 12 estados × venda/doação |
| Settlement | DELIVERED → COMPLETED | Estado nativo SETTLED, vault zero; matriz RPC |
| Cancelar off-chain | RESERVED / SHIPPING_QUOTATION / CARRIER_SELECTED / BUYER_MANAGED / WAITING_PAYMENT → CANCELLED | Sem preparação/conta blockchain; demais estados recusados |
| Cancelar nativo | INITIALIZED → CANCELLED: buyer OU producer; FUNDED → CANCELLED: buyer E producer | Estados SETTLED/CANCELLED/desconhecidos recusados; nenhum refund unilateral de FUNDED |
| Timeout pagamento | WAITING_PAYMENT → EXPIRED | Somente sem preparação/conta; lote válido reabre; lote vencido expira |
| Proof of Rescue | Doação entregue sem escrow, ou concluída com settlement → COMPLETED / DONATED | Producer e NGO assinam; hash canônico e contas conferidos |

`TradeStatus::CarrierSelected` e `BuyerManaged` existem no enum, mas a escolha logística é persistida em ShippingRequest e o trade segue diretamente para WAITING_PAYMENT. NGO_MANAGED é o nome conceitual do fluxo; a request usa BUYER_MANAGED para o destinatário NGO. `producer_delivery` permanece apenas como valor legado reconhecível no enum e é rejeitado pelas requests de lote; não há fluxo operacional para ele. `OfferStatus::Withdrawn` não possui endpoint de transição implementado. Esses estados não foram inventados como novos endpoints.

As matrizes cobrem os guards acima, não o produto cartesiano de todos os estados, papéis, horários e respostas de rede. O programa nativo não tem testes de execução completa de todas as CPIs no runtime Solana; essa limitação permanece explícita.

## Concorrência, segurança e E2E

`PostgresConcurrencyTest` mantém inicialmente o lock da linha, inicia dois processos PHP independentes, observa ambos esperando `Lock` em `pg_stat_activity`, libera e exige resultados **201/409**, um único trade e lote reservado. O histórico de trade expirado é testado separadamente, permitindo nova venda legítima.

Wallets: assinatura válida, assinatura inválida, mensagem/nonce alterado, wallet diferente, expiração, replay, challenge de outro usuário, troca direta e operação financeira sem verificação. Registros e mudanças de wallet não aceitam private key ou seed phrase. Autorização por actor, policies, permissões administrativas, mínimo privado, ratings somente COMPLETED, intervalo 1–5, relações permitidas e unicidade/reputação estão cobertos pelas suítes existentes e regressões.

Os quatro cenários de `EndToEndValidationTest` atravessam o kernel HTTP com tokens Sanctum de login real. A limitação de taxa é desativada somente nessa classe para o cadastro concentrado dos atores; autenticação e autorização permanecem ativas. As outras suítes mantêm os testes próprios de segurança.

1. Comercial: producer/buyer/carrier cadastrados com Ed25519 → lote → buy-now → frete → initialize/fund → retirada/coleta/entrega → settlement → ratings → dashboards.
2. Refund: mesmos passos até funding → preparação exige dois signers → confirmação CANCELLED/vault zero → reabertura → dashboard sem gasto definitivo.
3. Doação com carrier: NGO aceita → escrow somente frete → entrega → settlement → Proof of Rescue → DONATED/COMPLETED → rating/impacto.
4. Doação NGO-managed: sem escrow → entrega pela NGO → Proof of Rescue → DONATED/COMPLETED → rating/impacto.

## Layout Rust ↔ Laravel e finanças

Fixtures produzidas por `solana/examples/export_layout.rs` são lidas pelos decoders reais do Laravel em `RustLayoutCompatibilityTest`; não são serializers PHP que se validam entre si.

| Estado | Tamanho | Layout / offsets (bytes, início zero) |
|---|---|---|
| ProtocolConfig v1 | 98 | version 0; bump 1; authority 2; treasury 34; mint 66 |
| TradeState v2 | 244 | version 0; trade_bump 1; vault_bump 2; id 3; buyer 11; producer 43; carrier 75; mint 107; vault 139; protocol 171; product 203; shipping 211; fee 219; total 227; expires 235; status 243 |
| RescueProofState v1 | 146 | version 0; bump 1; id 2; producer 10; NGO 42; carrier 74; hash 106; created_at 138 |

Inteiros de 8 bytes usam little-endian; pubkeys/hash têm 32 bytes. Instruções 0–5 exigem respectivamente **105, 1, 33, 1, 1, 73 bytes**, testadas contra comprimentos errados.

Seeds verificadas no Rust: `foodrescue_protocol + authority`; `foodrescue_trade + trade_id_le`; `foodrescue_vault + trade_id_le`; `foodrescue_rescue + trade_id_le`. O backend verifica owner/layout/termos do PDA, mas não recalcula independentemente o bump usando uma biblioteca de curva: a derivação é validada pelo programa.

ProtocolConfig é lido no settlement nativo e determina a treasury. A token account de destino deve ter a mint correta e owner igual à treasury registrada. O frontend não substitui esse destino pelo corpo de uma confirmação.

```text
fee_base = floor(product_base * 200 / 10000)
seller_base = product_base - fee_base
total_base = product_base + shipping_base
seller_base + fee_base + shipping_base = total_base
```

Exemplo testado: produto 100.123456, frete 12.345678, taxa 2.002469 (truncada na menor unidade); buyer total 112.469134. Doação tem produto/taxa zero; frete não é taxado; NGO-managed não cria escrow. Refund envia 100% da vault ao buyer, sem pagamento a producer/treasury/carrier.

Dashboards definitivos filtram COMPLETED, excluem doações da receita comercial e agrupam quantidades por unidade. `recovered_revenue` e `producer_revenue_recovered` representam o recebimento líquido do produtor (`product_amount - protocol_fee`). Nenhuma conversão automática kg/t/box foi introduzida.

## Riscos restantes e decisões humanas

1. **Autorização das seeds de trade/proof — resolvido nesta rodada.** Trade e proof continuam usando o ID sequencial do banco nas seeds, mas `initialize_trade` agora exige a assinatura da authority registrada no `ProtocolConfig`, e `create_rescue_proof` exige NGO, producer e a mesma authority. O programa valida on-chain a relação entre a authority assinante e o `ProtocolConfig`; o Laravel inclui a assinatura exigida nas instruções, snapshots e verificação RPC. Isso impede que um terceiro antecipe um ID e ocupe o PDA sem a atestação da autoridade. A alteração de contas está documentada em `solana/README.md`; trades/proofs já existentes não são migrados retroativamente.
2. **Preparação abandonada — resolvido nesta rodada com janela de reconciliação.** Após o prazo de pagamento, o scheduler mantém a reserva por `BLOCKCHAIN_RECONCILIATION_GRACE_MINUTES` (30 por padrão) e consulta `getProgramAccounts` filtrando o `TradeState` pelo ID e tamanho esperado. Só libera o lote quando a RPC retorna ausência de estado on-chain; conta encontrada, resposta inválida ou falha de RPC mantém a reserva para nova tentativa. A reconciliação é fail-safe e não apaga o snapshot. A ausência de estado em RPC não prova que uma transação futura não será transmitida depois da consulta; a janela adicional reduz esse risco, mas a fronteira RPC permanece coberta pelo item 9.
3. **Estados operacionais — resolvido nesta rodada para trades com escrow.** O `TradeState` agora possui `READY_FOR_PICKUP`, `IN_TRANSIT` e `DELIVERED`, com instruções 6, 7 e 8 assinadas pelo producer e pelo responsável logístico. O programa só aceita `settle_trade` em `DELIVERED` e só aceita cancelamento de escrow em `FUNDED`, impedindo os saltos diretos descritos. O Laravel prepara/confirma essas instruções e só mantém o caminho off-chain para doações NGO-managed sem escrow, que não possuem `TradeState` financeiro.
4. **Tokens extras no settlement — decisão aplicada: opção 2.** O settlement aceita saldo igual ou superior ao total contratado. O produtor, treasury e carrier recebem somente os valores contratados; o excedente é transferido automaticamente para a token account do buyer, e a auditoria registra o valor devolvido.
5. **Definição de receita do producer — decisão aplicada: opção 2.** As métricas `recovered_revenue` e `producer_revenue_recovered` agora representam o recebimento líquido (`product_amount - protocol_fee`). O produto bruto continua disponível em `product_amount`, e a taxa permanece separada em `protocol_fee`.
6. **Fallback logístico — decisão aplicada: opção 1.** O comportamento oficial permanece `BUYER_MANAGED` após timeout, inclusive quando o lote não autorizou `buyer_pickup`. `PRODUCER_DELIVERY` não é uma modalidade disponível: é rejeitado na criação/atualização de lotes e não possui endpoint operacional.
7. **Doação com carrier e prova — decisão aplicada: opção 2.** O settlement financeiro do frete move o trade para `PROOF_PENDING`; somente a confirmação on-chain do Proof of Rescue, com assinaturas da NGO e do produtor, move para `COMPLETED` e marca o lote como `DONATED`. Doações NGO-managed sem frete seguem `DELIVERED → Proof → COMPLETED`.
8. **Rollback com histórico — decisão aplicada: opção 1.** O histórico de trades é append-only e não será apagado, consolidado ou reescrito para forçar a restauração da unicidade global. O rollback integral das migrations continua válido em banco vazio; em bases com múltiplas tentativas por lote, a migration que restaura a unicidade global não deve ser executada sem um procedimento de dados explicitamente aprovado.
9. **RPC — decisão aplicada: opção 1.** A aplicação mantém o endpoint configurado como fronteira de confiança operacional, exigindo commitment `confirmed/finalized`, validação estrita de status, slot, programa, PDA, signers, instruções e evidências de saldo. Respostas inválidas, incompletas, com erro, timeout ou sem `innerInstructions`/balances necessários ao refund são rejeitadas. Não foram adicionados quorum de provedores, cliente leve ou política de reorg; essa limitação permanece explícita para operação com fundos reais.
10. **Termos e wallets originais — decisão aplicada: opção 1.** Os termos capturados no snapshot e as wallets originais do trade permanecem imutáveis para operações on-chain. A troca de wallet não migra contas existentes; trades já preparados continuam exigindo a wallet original. Não há recuperação ou rotação automática de wallet, e uma recuperação operacional deverá ser tratada fora do protocolo financeiro.

## O que não foi testado

- Execução financeira real na Devnet: `SOLANA_PROGRAM_ID`, mint, authority e treasury operacionais não estavam configurados. Nenhum deploy foi solicitado/executado.
- Assinatura/interação real de extensão de wallet no navegador, rent/compute limits, CPI SPL completa, congestionamento, falhas de rede real e reorganizações de cadeia.
- O teste nativo de refund intercepta `TransferChecked` e confere valor/estado/guards; ele **não executa SPL Token nem prova saldos de contas num validator**.
- Fuzzing exaustivo, carga sustentada ou todos os interleavings de cancelamento/aceite/scheduler; concorrência simultânea foi comprovada especificamente para buy-now.
- Migrations sobre uma cópia externa com dados antigos inconsistentes; ausência de duplicatas foi verificada no ambiente disponível.
- Persistência das ferramentas rustfmt/clippy se a imagem Solana for recriada sem instalá-las. O comando de instalação está no README.

## Arquivos e evidências

O workspace não contém metadados Git para gerar diff de commit. Foi guardado snapshot local anterior às correções em `/tmp/foodrescue-validation-original.tar.gz`, com hashes em `/tmp/foodrescue-validation-original.json`. A lista de arquivos de código/documentação modificados e adicionados consta em [VALIDATION_FILES.md](VALIDATION_FILES.md); inclui ajustes de formatação/imports feitos pelo Pint.

Evidências disponíveis no container PHP: `/tmp/foodrescue-tests.log`, `/tmp/foodrescue-pint-final.log`, `/tmp/foodrescue-migrations.log` e logs auxiliares de Composer/rotas. Fixtures binárias estão em `solana/fixtures` e `www/tests/Fixtures/Solana`. O build está em `solana/target/deploy/foodrescue.so`. Esses logs e artefatos são locais; não houve publicação externa.
