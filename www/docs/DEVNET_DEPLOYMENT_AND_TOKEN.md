# FoodRescue — Deploy na Solana Devnet e token de teste

Este guia descreve o deploy do programa FoodRescue na Solana Devnet, a criação de uma mint SPL clássica, a distribuição de tokens de teste e a configuração de nome e símbolo via Metaplex Token Metadata.

O procedimento usa os containers existentes:

- `food-rescue-solana`;
- `food-rescue-node`;
- `food-rescue`.

Não é necessário usar `compose.validation.yaml`.

## Segurança

- Nunca coloque seed phrases ou chaves privadas no `.env`, no Laravel ou no Git.
- Compartilhe somente endereços públicos e signatures.
- O keypair do programa deve ser preservado para futuros upgrades do mesmo Program ID.
- Uma seed phrase exibida em logs deve ser considerada comprometida.

## Papéis das wallets

| Papel | Função |
|---|---|
| Deployer / Upgrade Authority | Faz deploy, paga taxas e atualiza o programa |
| Protocol Authority | Assina inicialização do protocolo, trades e Proof of Rescue |
| Treasury | Recebe as taxas do protocolo |

Exemplo atual da Devnet:

```text
Protocol Authority: 5wbZZiSzAbn7g8obEUJCQrdZTC7y6UrKuazvY7CVcu7T
Treasury:           2WGhiqYXjw57REGfvjqJeQuxNXKz7QLjmU1XtxvhP58c
```

O endereço do deployer e o Program ID dependem dos keypairs locais.

## 1. Entrar no container Solana

```bash
docker exec -it food-rescue-solana bash
```

Configurar a Devnet:

```bash
solana config set --url https://api.devnet.solana.com
```

## 2. Criar os keypairs

Se ainda não existirem:

```bash
mkdir -p /workspace/keypar

solana-keygen new \
  --outfile /workspace/keypar/foodrescue-devnet-deployer.json

solana-keygen new \
  --outfile /workspace/keypar/foodrescue-devnet-program.json
```

Guarde as seed phrases de forma segura e não as envie pelo chat.

Confira apenas os endereços públicos:

```bash
solana address \
  -k /workspace/keypar/foodrescue-devnet-deployer.json

solana address \
  -k /workspace/keypar/foodrescue-devnet-program.json
```

O deployer precisa receber SOL de teste para pagar o deploy e as contas SPL. O envio pode ser feito pelo faucet da Devnet ou pela Phantom configurada para Devnet.

## 3. Fazer o deploy do programa

O script está em [`../../solana/deploy-devnet.sh`](../../solana/deploy-devnet.sh).

```bash
SOLANA_PROTOCOL_AUTHORITY=5wbZZiSzAbn7g8obEUJCQrdZTC7y6UrKuazvY7CVcu7T \
SOLANA_PROTOCOL_TREASURY=2WGhiqYXjw57REGfvjqJeQuxNXKz7QLjmU1XtxvhP58c \
DEPLOYER_KEYPAIR=/workspace/keypar/foodrescue-devnet-deployer.json \
PROGRAM_KEYPAIR=/workspace/keypar/foodrescue-devnet-program.json \
bash /workspace/deploy-devnet.sh
```

O script compila o programa, faz o deploy e exibe o Program ID.

Para atualizar o mesmo programa depois de uma alteração compatível:

```bash
SOLANA_PROTOCOL_AUTHORITY=5wbZZiSzAbn7g8obEUJCQrdZTC7y6UrKuazvY7CVcu7T \
SOLANA_PROTOCOL_TREASURY=2WGhiqYXjw57REGfvjqJeQuxNXKz7QLjmU1XtxvhP58c \
DEPLOYER_KEYPAIR=/workspace/keypar/foodrescue-devnet-deployer.json \
PROGRAM_KEYPAIR=/workspace/keypar/foodrescue-devnet-program.json \
bash /workspace/deploy-devnet.sh --allow-existing
```

O `--allow-existing` só deve ser usado quando o deployer for a Upgrade Authority do programa.

Verifique a autoridade de upgrade:

```bash
solana program show PROGRAM_ID \
  --url devnet \
  --keypair /workspace/keypar/foodrescue-devnet-deployer.json
```

Para um contrato incompatível, gere outro `PROGRAM_KEYPAIR`. Isso cria outro Program ID e exige novo `ProtocolConfig`.

## 4. Configurar o Laravel

No arquivo `www/.env`:

```env
SOLANA_CLUSTER=devnet
SOLANA_RPC_URL=https://api.devnet.solana.com
SOLANA_COMMITMENT=confirmed
SOLANA_PROGRAM_ID=PROGRAM_ID
SOLANA_TOKEN_MINT=
SOLANA_PROTOCOL_AUTHORITY=5wbZZiSzAbn7g8obEUJCQrdZTC7y6UrKuazvY7CVcu7T
SOLANA_PROTOCOL_TREASURY=2WGhiqYXjw57REGfvjqJeQuxNXKz7QLjmU1XtxvhP58c
```

Depois limpe o cache:

```bash
docker exec food-rescue php artisan optimize:clear
```

O `www/.env.example` deve manter os endereços reais vazios.

## 5. Criar a mint SPL clássica

Configure o deployer como signer e fee payer:

```bash
solana config set \
  --url https://api.devnet.solana.com \
  --keypair /workspace/keypar/foodrescue-devnet-deployer.json
```

Crie uma mint com seis casas decimais:

```bash
spl-token create-token \
  --decimals 6 \
  --fee-payer /workspace/keypar/foodrescue-devnet-deployer.json \
  --url devnet
```

Guarde o endereço retornado como `MINT_ADDRESS` e configure:

```env
SOLANA_TOKEN_MINT=MINT_ADDRESS
```

O contrato atual exige o SPL Token clássico `Tokenkeg...`. Não use `--program-2022` nem `--enable-metadata` na criação da mint.

## 6. Criar a token account do buyer

Uma wallet Solana comum precisa de uma token account para cada mint.

```bash
MINT=MINT_ADDRESS
BUYER=BUYER_PUBLIC_KEY
DEPLOYER=/workspace/keypar/foodrescue-devnet-deployer.json
```

```bash
spl-token create-account "$MINT" \
  --owner "$BUYER" \
  --fee-payer "$DEPLOYER" \
  --url devnet
```

Guarde o endereço retornado como `BUYER_TOKEN_ACCOUNT`.

## 7. Emitir tokens de teste

```bash
spl-token mint "$MINT" \
  1000000 \
  BUYER_TOKEN_ACCOUNT \
  --mint-authority "$DEPLOYER" \
  --fee-payer "$DEPLOYER" \
  --url devnet
```

Com seis casas decimais, `1000000` representa 1.000.000 tokens de teste.

Verifique o saldo:

```bash
spl-token balance "$MINT" \
  --address BUYER_TOKEN_ACCOUNT \
  --url devnet
```

Producer, carrier e treasury também precisam de token accounts criadas para receber pagamentos do settlement, mas não precisam começar com saldo.

## 8. Adicionar nome e símbolo

A mint clássica não possui nome e símbolo no comando `create-token`. O helper [`set-token-metadata.mjs`](../../solana/set-token-metadata.mjs) cria metadata Metaplex sem trocar a mint para Token-2022.

O volume Solana deve estar montado no container Node em `/workspace`.

Copie o script para uma pasta temporária:

```bash
docker exec food-rescue-node sh -lc '
mkdir -p /tmp/foodrescue-token-metadata &&
cp /workspace/set-token-metadata.mjs /tmp/foodrescue-token-metadata/
'
```

Instale as dependências na pasta temporária:

```bash
docker exec food-rescue-node sh -lc '
cd /tmp/foodrescue-token-metadata &&
npm init -y &&
npm install @metaplex-foundation/umi \
  @metaplex-foundation/umi-bundle-defaults \
  @metaplex-foundation/mpl-token-metadata
'
```

Execute usando o deployer como mint authority e update authority:

```bash
docker exec food-rescue-node sh -lc '
cd /tmp/foodrescue-token-metadata &&
MINT_ADDRESS=MINT_ADDRESS \
MINT_AUTHORITY_KEYPAIR=/workspace/keypar/foodrescue-devnet-deployer.json \
TOKEN_NAME="FoodRescue Test Dollar" \
TOKEN_SYMBOL="FRUSD" \
TOKEN_METADATA_URI="https://SEU-ENDERECO/foodrescue-frusd.json" \
node set-token-metadata.mjs
'
```

O `TOKEN_METADATA_URI` deve apontar para um JSON público. Um exemplo:

```json
{
  "name": "FoodRescue Test Dollar",
  "symbol": "FRUSD",
  "description": "Token de teste do FoodRescue na Solana Devnet"
}
```

O nome e o símbolo são gravados na metadata on-chain. A Phantom ou explorers podem demorar para atualizar o cache.

## 9. Inicializar o ProtocolConfig

Antes de usar trades blockchain, o `ProtocolConfig` precisa ser inicializado:

1. derivar o PDA com o Program ID e a Protocol Authority;
2. chamar o endpoint administrativo de preparação;
3. assinar a transação com a wallet da Protocol Authority;
4. enviar a transação para a Devnet;
5. confirmar a signature no Laravel.

A Protocol Authority não precisa ser a mesma wallet que controla upgrades do programa. No ambiente de teste, o deployer controla upgrades e a authority assina operações do protocolo.

## Resultado esperado

Ao final, haverá:

- programa FoodRescue publicado na Devnet;
- Program ID configurado no Laravel;
- mint SPL clássica com seis decimais;
- buyer abastecido com tokens de teste;
- nome `FoodRescue Test Dollar`;
- símbolo `FRUSD`;
- Protocol Authority e Treasury configuradas.

O contrato utiliza o endereço da mint e os decimals. Nome e símbolo servem para identificação visual em wallets e explorers.
