# FoodRescue — E2E on-chain na Solana Devnet

Este documento registra o fluxo executado com wallets temporárias na Devnet. As chaves privadas permanecem no volume montado em `solana/keypar/devnet-e2e/` e nunca devem ser copiadas para o chat, para o repositório ou para o Laravel.

## Recursos usados

- Cluster: `devnet`
- Programa: `Ex6CN32gBUH2JALUwbqyn8ZMwWmBNrrHa4sjDNMAm5sd`
- Mint FRUSD: `9tVPExJFkBU3yLgyo8fVzFVQj2t2boEESSpmoYikfxRr`
- ProtocolConfig temporário: `9P1yVwr9hkJCrXRX8jUtDX4xzA43wkhj4LQd83GKNmMe`
- Configuração persistida: `solana/devnet-e2e-state.md`

## Pré-requisitos

Os containers `food-rescue`, `food-rescue-node` e `food-rescue-solana` devem estar em execução, com `solana/` montado em `/workspace` no container Node. Os scripts Node reutilizam as dependências instaladas em `/tmp/foodrescue-devnet-e2e/node_modules`.

Todas as execuções abaixo ocorrem dentro dos containers existentes:

```bash
docker exec food-rescue-node sh -lc \
  'NODE_PATH=/tmp/foodrescue-devnet-e2e/node_modules node /workspace/<script>.cjs'
```

Os scripts leem tokens de API e keypairs do volume persistente. Eles gravam checkpoints locais com extensão `.local.json`; esses arquivos contêm estado operacional e devem permanecer fora do versionamento.

## Fluxo comercial já executado

1. Criar lote, compra, solicitação de transporte e oferta:
   `create-commercial-devnet-e2e.mjs`.
2. Preparar e assinar `initialize_trade`:
   `execute-devnet-initialize-e2e.cjs`.
3. Assinar o funding:
   `execute-devnet-funding-e2e.cjs`.
4. Assinar `ready-for-pickup`, `pickup` e `delivered`:
   `execute-devnet-delivery-e2e.cjs`.
5. Liquidar o escrow:
   `execute-devnet-settlement-e2e.cjs`.

Resultado: trade comercial `1` em `completed`, com distribuição do escrow e vault zerada.

## Fluxo de doação já executado

1. Criar e aceitar a doação, criar transporte e selecionar oferta:
   `create-donation-devnet-e2e.cjs`.
2. Criar a token account da NGO e abastecer o frete com FRUSD.
3. Inicializar o trade e confirmar no backend:
   `execute-devnet-donation-initialize-e2e.cjs`.
4. Financiar o escrow:
   `execute-devnet-donation-funding-e2e.cjs`.
5. Executar as três transições de entrega:
   `execute-devnet-donation-delivery-e2e.cjs`.
6. Liquidar o frete:
   `execute-devnet-donation-settlement-e2e.cjs`.
7. Confirmar a liquidação existente após qualquer migration pendente:
   `confirm-devnet-donation-settlement-e2e.cjs`.
8. Preparar, assinar e confirmar o Proof of Rescue:
   `execute-devnet-rescue-proof-e2e.cjs`.

Resultado: trade de doação `2` passou por `proof_pending` e terminou em `completed`; o Proof of Rescue foi gravado on-chain e confirmado no Laravel.

## Retomada após interrupção

Consultar primeiro [devnet-e2e-state.md](../../solana/devnet-e2e-state.md) e os arquivos locais em `solana/keypar/devnet-e2e/`. Não reenviar uma transação cujo checkpoint já contenha assinatura. Para uma confirmação interrompida depois do envio on-chain, reutilizar a assinatura existente com o script de confirmação correspondente.

## Verificação

No container da aplicação:

```bash
docker exec food-rescue php artisan test
```

O último ciclo completo validado terminou com 332 testes e 1.134 asserções; o ciclo específico após o fluxo de doação passou com 30 testes e 256 asserções.
