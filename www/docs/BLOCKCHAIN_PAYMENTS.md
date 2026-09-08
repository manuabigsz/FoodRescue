# FoodRescue — Solana Devnet / Escrow Funding

## Escopo implementado

Implementado:

- wallet pública Solana por usuário;
- preparação de instruções para a wallet do buyer;
- programa Solana nativo Rust, sem Anchor;
- `initialize_trade`;
- PDA de estado do trade;
- PDA vault SPL Token;
- `fund_trade` com `TransferChecked`;
- validação on-chain da taxa de 2%;
- deadline de funding no programa;
- validação RPC no Laravel;
- persistência de PDA/vault/signatures/slots;
- `WAITING_PAYMENT -> FUNDED` somente depois da verificação on-chain.

Também implementados: pickup/in-transit/delivery, settlement, cancelamento/refund, doação e Proof of Rescue. Consulte os documentos específicos e o [relatório de validação](VALIDATION_REPORT.md).

## Variáveis

```dotenv
SOLANA_CLUSTER=devnet
SOLANA_RPC_URL=https://api.devnet.solana.com
SOLANA_COMMITMENT=confirmed
SOLANA_PROGRAM_ID=
SOLANA_TOKEN_MINT=
SOLANA_PROTOCOL_TREASURY=
SOLANA_PROTOCOL_AUTHORITY=
```

Authority e treasury do ambiente são parâmetros de bootstrap. Depois da confirmação, o ProtocolConfig PDA associado ao trade determina treasury e mint. O settlement valida o owner da token account da treasury contra esse estado.

## Endpoints

```http
POST /api/v1/trades/{trade}/blockchain/prepare
POST /api/v1/trades/{trade}/blockchain/initialize/confirm
POST /api/v1/trades/{trade}/blockchain/funding/confirm
GET  /api/v1/trades/{trade}/blockchain
```

### Prepare

O backend retorna:

- Program ID;
- mint;
- decimals consultados via RPC `getTokenSupply`;
- valores em base units;
- wallets esperadas;
- seeds dos PDAs;
- instruction data em Base64;
- ordem, signer e writable das accounts.

O Laravel não assina a transação. O front-end monta a transação a partir dessa resposta — derivando os PDAs pelas seeds e as contas de token pelo endereço associado da mint — e pede a assinatura à carteira do ator. Contas de token associadas que ainda não existirem entram na mesma transação como `CreateIdempotent`, pagas por quem assina; se a instrução do programa falhar depois, a criação volta atrás junto.

Uma instrução com mais de um signatário não é enviável por uma extensão só, porque o `recentBlockhash` expira antes de dois atores assinarem em momentos diferentes. Por isso o Proof of Rescue é feito em duas transações — a NGO abre, o produtor confirma. O caso que ainda exige duas assinaturas juntas é o cancelamento com escrow financiado (comprador e produtor): a tela detecta e avisa antes de o usuário assinar.

### Initialize

A wallet do buyer assina a instrução `initialize_trade`. O programa cria:

```text
trade PDA = ["foodrescue_trade", trade_id u64 little-endian, buyer pubkey]
vault PDA = ["foodrescue_vault", trade_id u64 little-endian, buyer pubkey]
```

A vault é uma SPL Token Account cuja authority é o trade PDA.

Depois do envio, o frontend chama `initialize/confirm` com:

```json
{
  "signature": "...",
  "trade_pda": "...",
  "vault_token_account": "..."
}
```

O Laravel não confia apenas na signature. Ele consulta Devnet e verifica:

1. signature confirmada/finalizada e sem erro;
2. transação invocou `SOLANA_PROGRAM_ID`;
3. a instrução de tag e tamanho esperados referencia o PDA informado e a transação contém os signers exigidos;
4. PDA pertence ao programa;
5. estado binário do PDA corresponde ao trade do PostgreSQL;
6. buyer/producer/carrier/mint/vault/valores/deadline coincidem;
7. vault pertence ao SPL Token Program, à mint configurada e tem authority igual ao trade PDA.

### Funding

A wallet executa `fund_trade`. O programa:

1. valida buyer;
2. valida PDA/vault/mint;
3. valida deadline usando `Clock`;
4. valida token account do buyer;
5. executa `TransferChecked` de `product_amount + shipping_amount` para a vault;
6. marca estado on-chain como funded.

O Laravel consulta novamente a cadeia e só então altera:

```text
WAITING_PAYMENT -> FUNDED
```

## Taxa

```text
protocol_fee = product_amount * 200 / 10000
buyer_total  = product_amount + shipping_amount
```

A taxa está contida em `product_amount`; não é adicionada ao total do buyer. O frete não é taxado.

## Observações de segurança

- nunca persistir private keys ou seed phrases;
- a wallet do buyer assina diretamente;
- valores são comparados em base units inteiros (`u64`);
- a mint fornece os decimals via RPC;
- o programa rejeita funding após o deadline;
- settlement é uma instrução on-chain separada, tag 3;
- signature de transação é Base58 com 64 bytes decodificados; assinatura de mensagem Ed25519 permanece Base64;
- slots de getTransaction e getSignatureStatuses devem coincidir, e signatures já registradas são rejeitadas.

## Delivery settlement

After funding, settlement is only prepared when the backend trade status is `DELIVERED`.

```http
POST /api/v1/trades/{trade}/blockchain/settlement/prepare
POST /api/v1/trades/{trade}/blockchain/settlement/confirm
```

The buyer signs instruction tag `3` (`settle_trade`). The backend does not sign on behalf of any actor. Completion is persisted only after RPC validation shows TradeState `STATUS_SETTLED` and the escrow vault token balance is exactly zero; any balance above the contracted amount is returned to the buyer by the program.

## Preparação e timeout

A primeira preparação persiste um snapshot dos termos, wallets, mint e deadline. As confirmações usam esses mesmos termos, mesmo que a assinatura seja reportada depois do prazo. O programa continua proibindo funding no instante do deadline ou depois dele.

Uma preparação emitida ou uma conta blockchain registrada impede cancelamento exclusivamente off-chain e reabertura automática no timeout. Isso evita revender um lote cujo escrow foi financiado sem confirmação no backend. Preparações abandonadas exigem reconciliação: não limpar o snapshot manualmente sem comprovar o estado da cadeia. O MVP ainda não automatiza a reconciliação de ausência de broadcast.

Valores em base units precisam caber em u64. O backend compara a taxa persistida com floor(product_base_units × 200 / 10000) e rejeita mint/valor cuja precisão não preserve essa igualdade. A validação financeira completa usou mint simulada com seis decimais.
