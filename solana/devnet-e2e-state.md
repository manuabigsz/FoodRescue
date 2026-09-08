# FoodRescue Devnet E2E — estado persistente

Este arquivo contém somente endereços públicos e checkpoints. As chaves privadas estão no volume Solana em `keypar/devnet-e2e/` e não devem ser copiadas para o chat ou para o Laravel.

## Rede e programa

```text
Cluster: devnet
RPC: https://api.devnet.solana.com
Program ID: Ex6CN32gBUH2JALUwbqyn8ZMwWmBNrrHa4sjDNMAm5sd
Mint: 9tVPExJFkBU3yLgyo8fVzFVQj2t2boEESSpmoYikfxRr
Upgrade Authority / deployer: 4mgxETzvnCVbWPaxndZBHi8Rg3hK1uJphezKGT47h4vg
Mint authority: 4mgxETzvnCVbWPaxndZBHi8Rg3hK1uJphezKGT47h4vg
Production Protocol Authority: 5wbZZiSzAbn7g8obEUJCQrdZTC7y6UrKuazvY7CVcu7T
Production Treasury: 2WGhiqYXjw57REGfvjqJeQuxNXKz7QLjmU1XtxvhP58c
Temporary ProtocolConfig PDA: 9P1yVwr9hkJCrXRX8jUtDX4xzA43wkhj4LQd83GKNmMe
```

## Wallets temporárias E2E

| Papel | Endereço | Keypair persistente |
|---|---|---|
| Authority temporária | `5f1CriQaNHmXxj9uDHmFDTb79qLnec1E2zK11em1wftV` | `keypar/devnet-e2e/authority.json` |
| Treasury temporária | `G7QtKUSLYiYcdtzUAUdyyxg7jjuGoTrqePspnmE49mbv` | `keypar/devnet-e2e/treasury.json` |
| Buyer temporário | `FyEbrkBEeoyNF3HyyaMbc51cKNL1X1uoysb2qdfoy3Qe` | `keypar/devnet-e2e/buyer.json` |
| Producer temporário | `ATB7z1UtmGPGgMbvvcMfLuXdBvtsfbD1k77A4yHymvap` | `keypar/devnet-e2e/producer.json` |
| Carrier temporário | `723bi7HcVzgTX2W8tm3jTkbMDP4WZJLEq8aYXTMeXXUt` | `keypar/devnet-e2e/carrier.json` |
| NGO temporária | `84FPXwWEEymP14ZgafXMMbqueGTq8S6wsKZgndzwpd5n` | `keypar/devnet-e2e/ngo.json` |

## Checkpoint

- [x] Programa publicado e consultável na Devnet.
- [x] Mint SPL clássica criada com 6 decimals.
- [x] Metadata de nome/símbolo executada para a mint.
- [x] Wallets temporárias geradas.
- [x] SOL/rent da Authority e NGO temporárias provisionado (0,05 SOL cada).
- [x] Token accounts temporárias criadas: buyer `3m7RmzVWHhsfxDyjdfbLcMLTqsRVt75fL144jukQt2Ac`, producer `8BAdPuSP3pnjTWSiDkQzM8aaNY4tkSmhDgQkQrNZKkoE`, carrier `Hxohbx3XnHnX7iHVRMmCBYevyNYqxwbMvfYJ9JVZS3nk`, treasury `2VtF7ou1ixvWpbMGx4C4beSjRXATPBGrfEtVeaiaWVje`.
- [x] ProtocolConfig temporário inicializado on-chain: `4SaGpqmNxpY2ckXp7CF2d7nussi61zBEmi8nkoSmjdPJ4ChoW9TgPvRFVbqc2kRcP6yr5QSvz1AMDuJyQg3eEiVU`.
- [x] Atores temporários registrados no Laravel: buyer user `2`, producer user `3`, carrier user `4`, NGO user `5`.
- [x] Produto de catálogo criado para o E2E: `FoodRescue Devnet E2E Product` (ID `1`).
- [x] Lote comercial criado: surplus lot `1`; trade `1`; shipping request `1`; shipping offer `1` selecionada.
- [x] `initialize_trade` executado e confirmado: `4shHuiV9sj224EfsZWkcxLNCdQydoodfDc2RsQMLCjywEx75UceFm6crYp9QEBb18uGV2KAgkc4fuQ6uYpFYJ9ZM`; trade PDA `DWK5SbaRg6hoR3NpCHLMXCUbaZX9qn92TJ8Vb4LkbYqQ`; vault `6qgNdViJWrLBLd9EkqDh4PkvN6nck3auM8dyCsxbKFL9`.
- [x] Funding do escrow executado e confirmado: `5kfFVxB8F515HQodDsVZmaNF5kZQTMF3BbWd2qjTCiLfHyRvUM657FsAF85tLBEgjXJnx4onnwu3Q4h8QP1QR7Nx`.
- [x] Entrega on-chain executada: ready `Dj6mg53KgGyYBh5WUeo3chuDsPWmRhiPZSfdsnCDhkMbGXuG1wjUzwdodD8HGb2UmLTrikiT2sMsgHrB5xqwWPp`; pickup `3coHLqi4fcpgGaCmmGfLEjVjdZy94YTgeifqXpqBCW3T84eg6zrV4dVynJThRNXY7Er1giR9kusKEuQcmiPTU6Lq`; delivered `5PXm5ST4oJ2pSSoWV4o2nat9VjLP8MqniPjF7Z2k2qWyDPQ9U3gDY24k31BUVAnpSnVsumtHkcWSnL6GshQw7pWb`.
- [x] Settlement executado: `63LPJNG7TwVFjNE3bLYj2rzmdyXY6Uv433syoLnLDVWyZGXw7eJcP9zB5ZXFCQAzN5QK3zQ6KXjL3p3z2vX7RSyn`; backend confirmado como `completed` e vault zerada.
- [x] Lote de doação criado: surplus lot `2`; trade `2`; shipping request `2`; shipping offer `2` selecionada.
- [x] Token account da NGO criada: `GFA5RoJdfKqcvngicNfxoTrigRcdjKY9ujeihkZ8wxdK`; 10 tokens mintados para o frete da doação.
- [x] Doação `initialize_trade`: `4Ng1RMGDxjNaZGkU3Ce3szxggyyH6XqSHDfwVMUFDK27yzvK3jh7Ym2ZBbVxxG1P2NESRys7X64WmWVqcNxNRwnK`; trade PDA `JD98BfFjUXZVRWRSGZHaqToVamRPhKiPbEeqXMgj1czp`; vault `4gN5tMAvVnbYcE5ppU3sqvugFpwsWFAmEhPrJrvD6gFX`.
- [x] Doação funding: `2L73bQS9YJLJBp3VHsgtAEUc8P3yifFKYoKHREBnCUZq12V72GBfHXjNqqBH5z7mFxkFVUWZVBbE8XYc43avDqsV`.
- [x] Entrega da doação: ready `39YYAk8qDJjMnpouewppQChkGcXYMrLGF1NGLneuFeXUrHnm7MX29Xv13BdbUwiS21ouYZ6kv6dbnoSddFpxy5e2`; pickup `4LUWzYvedB6fD3uyc1u82TP2LUjeVcdb6R7fcpSMWrNbVnxWmDEkZFcW4D932fLJk55SDwXYU1jutn9qpH1sBaAq`; delivered `39Er5ebgfaVmRYVxtjFjs5bNvjMkJrw2g2UN75etm7ePzdp4u37txQouhiBaBANj4awrsLpBtGcjyES245DvUMxt`.
- [x] Doação settlement: `5wwhzXf8of1gCJGzAuHxKso5Cs7nLRPL68LmKfDNE5XfMaxeVikBS7Ddg7YmaDgHhmu9qzmmrA7F88NUo6xMTeyw`; backend passou por `proof_pending` e vault foi zerada.
- [x] Proof of Rescue: `26HS5wvqGcFCLyqTcR7jVJzPqUPtXkKeb9cuxX2USRLS4padHs1ViGkmoWZH9x49uwVk68QhULZYTPcmE7dAxwyV`; PDA `kuyG4re5RzXUohKuAGHr3y3FJ2zpyTxDi3sHwXVWQ47`; backend final `completed`.

## Próxima retomada

Os fluxos comercial e de doação foram concluídos. O estado pode ser reutilizado para auditoria sem expor as chaves privadas.
