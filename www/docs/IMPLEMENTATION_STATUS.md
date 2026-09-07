# FoodRescue — estado consolidado

Atualizado na validação de 7 de setembro de 2026. Referência funcional: [FOODRESCUE_IMPLEMENTATION.md](FOODRESCUE_IMPLEMENTATION.md). Evidências e limitações: [VALIDATION_REPORT.md](VALIDATION_REPORT.md).

## Implementado

- Cadastro transacional de producer, buyer, carrier e NGO, perfis, Sanctum, papéis e permissões.
- Prova de posse Ed25519, challenges de uso único com expiração, troca de wallet com nova verificação.
- Catálogos administrativos, excedentes, preço mínimo privado, ofertas, compra imediata e reserva com locks PostgreSQL.
- Solicitação e ofertas de frete, seleção de carrier, transporte pelo destinatário e timeouts pelo scheduler.
- Inicialização do ProtocolConfig, preparação e confirmação de escrow, funding, settlement e cancelamento/refund.
- Entrega: FUNDED → READY_FOR_PICKUP → IN_TRANSIT → DELIVERED → COMPLETED.
- Doação com frete em escrow ou transporte pela NGO sem escrow, e Proof of Rescue assinado por producer e NGO.
- Histórico de trades append-only; reaberturas preservam tentativas anteriores e não há rollback destrutivo para restaurar unicidade global em bases com histórico.
- Termos do snapshot e wallets originais permanecem imutáveis para operações on-chain; troca de wallet não migra trades já preparados.
- Ratings e reputação, dashboards por papel, métricas de impacto agrupadas por unidade.
- Programa Solana nativo com seis instruções, sem Anchor.

## Consolidação desta validação

Precisão financeira sem BCMath obrigatório; middleware de permissões registrado; SQL de dashboards corrigido; vínculo entre signature, instrução, PDA e signers; snapshot da preparação para preservar os termos originais; retenção da reserva quando pode existir funding não reportado; constraints PostgreSQL; timezone do challenge; expiração de lotes/ofertas; refund do saldo integral da vault, inclusive depósitos extras.

A aplicação, fila e scheduler executam nos containers existentes. Os testes usam PostgreSQL 17 em banco exclusivo. Os E2E passam pela API e simulam a RPC; não representam execução financeira na Devnet. O build SBF foi executado, sem deploy.

## Pendências de decisão

Consultar o relatório antes de operar com fundos reais: as decisões de autorização das seeds de trade/proof, reconciliação de preparação abandonada, saldo excedente no settlement, receita líquida do produtor, fallback logístico, Proof of Rescue obrigatório para doações com carrier, preservação do histórico append-only, validação estrita da RPC e imutabilidade dos termos/wallets originais já foram aplicadas conforme o relatório. Quorum de provedores, cliente leve, política de reorg e rotação automática de wallet continuam fora do escopo.
