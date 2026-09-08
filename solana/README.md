# FoodRescue Solana Program

Programa Solana nativo em Rust, sem Anchor.

## Instruções implementadas

- `0 - initialize_trade`: cria o PDA de estado e a vault SPL Token, grava buyer/producer/carrier, mint, valores e prazo.
- `2 - initialize_protocol`: inicializa authority, treasury e mint no ProtocolConfig PDA.
- `3 - settle_trade`: distribui produto líquido, taxa e frete.
- `4 - cancel_trade`: devolve todo o saldo da vault ao buyer.
- `5 - create_rescue_proof`: NGO assina e abre a atestação social como `PENDING_PRODUCER`.
- `1 - fund_trade`: transfere `product_amount + shipping_amount` da token account do buyer para a vault e marca o trade como funded.
- `6 - mark_ready_for_pickup`: producer assina `FUNDED → READY_FOR_PICKUP`.
- `7 - confirm_pickup`: carrier ou buyer/NGO assina `READY_FOR_PICKUP → IN_TRANSIT`.
- `8 - mark_delivered`: carrier ou buyer/NGO assina `IN_TRANSIT → DELIVERED`.
- `9 - confirm_rescue_proof`: producer assina e fecha a atestação, `PENDING_PRODUCER → CONFIRMED`.

O `trade_id` usado nas seeds é o ID sequencial do banco, portanto previsível. Em vez de exigir uma authority co-assinante, os PDAs carregam nas seeds quem precisa assinar a instrução: `initialize_trade` põe o buyer no trade e na vault, e `create_rescue_proof` põe NGO e producer no proof. Ocupar o PDA alheio passa a exigir a assinatura da parte envolvida, e cada instrução fica com um único signatário — o que a carteira do navegador consegue enviar sozinha. Por isso a atestação social é feita em duas instruções: a NGO abre com `create_rescue_proof` e o producer fecha com `confirm_rescue_proof`. Duas assinaturas na mesma transação não sobreviveriam a dois atores assinando em momentos diferentes, porque o `recentBlockhash` expira antes. Ambas recebem o `ProtocolConfig` para validar mint e configuração on-chain.

O programa valida `protocol_fee = product_amount * 200 / 10000` (2%). O frete não entra no cálculo da taxa.

## PDAs

```text
trade = PDA(["foodrescue_trade", trade_id_u64_le, buyer_pubkey])
vault = PDA(["foodrescue_vault", trade_id_u64_le, buyer_pubkey])
```

## Build

```bash
cargo build-sbf
```

## Deploy Devnet

```bash
solana config set --url devnet
solana program deploy target/deploy/foodrescue.so
```

Depois coloque o Program ID em `SOLANA_PROGRAM_ID` no Laravel.

## Observação

As nove instruções acima estão implementadas. O programa agora registra o lifecycle operacional de coleta e entrega; o Laravel prepara e confirma essas instruções. Consulte ../www/docs/VALIDATION_REPORT.md para resultados e limitações antes de qualquer deploy.


## ProtocolConfig PDA

Antes de criar trades, inicialize o `ProtocolConfig` oficial:

```text
seeds = ["foodrescue_protocol", authority_pubkey]
```

Estado:

```text
version
bump
authority
treasury
mint
```

A instrução `initialize_protocol` exige que `authority` assine e cria somente o PDA derivado dessa própria public key. Assim outra wallet não consegue inicializar o PDA oficial da authority do FoodRescue. `initialize_trade` e `create_rescue_proof` não pedem a authority como assinante: a ocupação antecipada dos PDAs é impedida pelas próprias seeds, que incluem as partes signatárias.

O backend deve configurar `SOLANA_PROTOCOL_AUTHORITY` e `SOLANA_PROTOCOL_TREASURY`, preparar a instrução administrativa, solicitar assinatura da authority e confirmar o estado on-chain.

Settlement só é aceito em `DELIVERED`; cancelamento de escrow financiado só é aceito em `FUNDED`. Assim uma chamada direta ao programa não pode liquidar antes da entrega nem cancelar depois da coleta.

Cada `TradeState` armazena `protocol_config`. O `settle_trade` usa esse endereço para validar o destino da taxa de protocolo.

## Settlement

Instruction tag `3` executes the final commercial settlement after the backend lifecycle reaches `DELIVERED`.

Required accounts, in order:

1. buyer (signer)
2. trade PDA (writable)
3. escrow vault SPL account (writable)
4. buyer SPL token account (writable)
5. ProtocolConfig PDA
6. producer SPL token account (writable)
7. protocol treasury SPL token account (writable)
8. configured mint
9. SPL Token Program
10. carrier SPL token account (writable, only when `shipping_amount > 0`)

The program validates destination account owners and mint before transferring. It pays `product_amount - protocol_fee` to the producer, `protocol_fee` to the treasury from ProtocolConfig and `shipping_amount` to the selected carrier. Any balance above the contracted total is returned to the buyer token account. For buyer-managed transport no carrier account is required.

## Cancelamento

A instrução `cancel_trade` (tag `4`) exige buyer OU producer no estado INITIALIZED e buyer E producer no estado FUNDED. Todo o saldo da vault, inclusive tokens extras, retorna à token account do buyer. O limite “antes da coleta” pertence ao backend, não ao estado nativo. A vault financiada deve conter pelo menos o total contratado; no settlement, o valor contratado é distribuído ao produtor, treasury e carrier, e qualquer excedente retorna automaticamente à token account do buyer.

## Proof of Rescue

A atestação social é feita em duas transações, uma por parte. A instrução `create_rescue_proof` (tag `5`) cria o PDA:

```text
rescue = PDA(["foodrescue_rescue", trade_id_u64_le, ngo_pubkey, producer_pubkey])
```

Contas:

1. NGO (signer + payer)
2. producer
3. ProtocolConfig PDA
4. Rescue Proof PDA (writable)
5. System Program

Depois, `confirm_rescue_proof` (tag `9`) fecha a atestação com duas contas: producer (signer) e o Rescue Proof PDA (writable). O programa confere que o `producer` gravado no estado é quem assina e que o status ainda é `PENDING_PRODUCER`.

O estado contém `trade_id`, wallets de producer/NGO/carrier, SHA-256 dos metadados canônicos da doação, timestamp e o status da atestação (`0 = PENDING_PRODUCER`, `1 = CONFIRMED`). O `RescueProofState` está na versão 2, com 147 bytes.

## Validação reproduzível no container existente

```bash
docker exec food-rescue-solana bash -lc 'rustup component add rustfmt clippy'
docker exec food-rescue-solana bash -lc 'cargo fmt --check && cargo test && cargo clippy --all-targets -- -D warnings && cargo build-sbf'
docker exec food-rescue-solana bash -lc 'cargo run --example export_layout'
```

`tests/validation.rs` cobre decoding estrito, matemática, guards e refund. A captura de CPI é um stub nativo: não executa o runtime SPL Token. `examples/export_layout.rs` serializa os estados Rust para comparação pelo decoder Laravel. Não foi usado validator local nem feito deploy.
