#!/usr/bin/env bash

set -Eeuo pipefail

# Deploy seguro do programa FoodRescue na Solana Devnet.
# As chaves privadas permanecem na máquina local e nunca são impressas.

IN_SOLANA_CONTAINER=0
if [[ -f /.dockerenv && -d /workspace ]]; then
    IN_SOLANA_CONTAINER=1
    ROOT_DIR=/workspace
else
    ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fi

PROGRAM_SO="${PROGRAM_SO:-$ROOT_DIR/target/deploy/foodrescue.so}"
DEPLOYER_KEYPAIR="${DEPLOYER_KEYPAIR:-$HOME/.config/solana/foodrescue-devnet-deployer.json}"
PROGRAM_KEYPAIR="${PROGRAM_KEYPAIR:-$HOME/.config/solana/foodrescue-devnet-program.json}"
AUTHORITY="${SOLANA_PROTOCOL_AUTHORITY:-}"
TREASURY="${SOLANA_PROTOCOL_TREASURY:-}"
BUILD=1
ALLOW_EXISTING_PROGRAM="${ALLOW_EXISTING_PROGRAM:-0}"

usage() {
    sed -n '2,12p' "$0"
    cat <<'HELP'

Uso:
  SOLANA_PROTOCOL_AUTHORITY=<pubkey> \
  SOLANA_PROTOCOL_TREASURY=<pubkey> \
  DEPLOYER_KEYPAIR=/caminho/deployer.json \
  PROGRAM_KEYPAIR=/caminho/program.json \
  bash solana/deploy-devnet.sh

Opções:
  --skip-build       usa o .so já existente, sem compilar no container
  --allow-existing   permite atualizar um programa já implantado

O script exige que os keypairs já existam. Gere-os manualmente, fora do
repositório, para que a seed phrase nunca seja capturada pelo log.
HELP
}

for arg in "$@"; do
    case "$arg" in
        --skip-build) BUILD=0 ;;
        --allow-existing) ALLOW_EXISTING_PROGRAM=1 ;;
        --help|-h) usage; exit 0 ;;
        *) echo "Argumento desconhecido: $arg" >&2; usage >&2; exit 2 ;;
    esac
done

die() {
    echo "ERRO: $*" >&2
    exit 1
}

command -v solana >/dev/null || die "CLI 'solana' não encontrada na máquina local."
command -v solana-keygen >/dev/null || die "CLI 'solana-keygen' não encontrada na máquina local."

[[ -r "$DEPLOYER_KEYPAIR" ]] || die "Keypair do deployer não encontrado: $DEPLOYER_KEYPAIR"
[[ -r "$PROGRAM_KEYPAIR" ]] || die "Keypair do programa não encontrado: $PROGRAM_KEYPAIR"
[[ -n "$AUTHORITY" ]] || die "Informe SOLANA_PROTOCOL_AUTHORITY com o endereço público."
[[ -n "$TREASURY" ]] || die "Informe SOLANA_PROTOCOL_TREASURY com o endereço público."

# Alguns subcomandos da Solana CLI consultam o signer padrão mesmo quando
# --keypair é informado em uma operação posterior.
solana config set \
    --url https://api.devnet.solana.com \
    --keypair "$DEPLOYER_KEYPAIR" >/dev/null

DEPLOYER_ADDRESS="$(solana address --keypair "$DEPLOYER_KEYPAIR")"
PROGRAM_ID="$(solana address --keypair "$PROGRAM_KEYPAIR")"
DEPLOYER_BALANCE="$(solana balance "$DEPLOYER_ADDRESS" --url devnet)"

echo "== FoodRescue / Solana Devnet =="
echo "Deployer/payer: $DEPLOYER_ADDRESS"
echo "Program ID:      $PROGRAM_ID"
echo "Authority:       $AUTHORITY"
echo "Treasury:        $TREASURY"
echo "Saldo deployer:  $DEPLOYER_BALANCE"
echo "RPC:             https://api.devnet.solana.com"
echo

if [[ "$BUILD" == "1" ]]; then
    if [[ "$IN_SOLANA_CONTAINER" == "1" ]]; then
        echo "[1/4] Compilando dentro do container Solana..."
        cd /workspace
        cargo build-sbf
    else
        command -v docker >/dev/null || die "Docker não encontrado; use --skip-build ou instale Docker."
        echo "[1/4] Compilando no container food-rescue-solana..."
        docker exec food-rescue-solana bash -lc 'cd /workspace && cargo build-sbf'
    fi
else
    echo "[1/4] Build ignorado (--skip-build)."
fi

[[ -r "$PROGRAM_SO" ]] || die "Artefato SBF não encontrado: $PROGRAM_SO"

if solana program show "$PROGRAM_ID" --url devnet --keypair "$DEPLOYER_KEYPAIR" >/dev/null 2>&1; then
    [[ "$ALLOW_EXISTING_PROGRAM" == "1" ]] || die "O Program ID já existe na Devnet. Use --allow-existing somente para upgrade consciente."
    echo "[2/4] Programa existente; upgrade explicitamente autorizado."
else
    echo "[2/4] Program ID ainda não encontrado na Devnet; deploy inicial."
fi

echo "[3/4] Fazendo deploy; a wallet deployer assinará localmente..."
solana program deploy "$PROGRAM_SO" \
    --program-id "$PROGRAM_KEYPAIR" \
    --keypair "$DEPLOYER_KEYPAIR" \
    --url devnet

echo "[4/4] Validando programa implantado..."
solana program show "$PROGRAM_ID" --url devnet --keypair "$DEPLOYER_KEYPAIR"

cat <<OUTPUT

=== CONFIGURAÇÃO PARA www/.env ===
SOLANA_CLUSTER=devnet
SOLANA_RPC_URL=https://api.devnet.solana.com
SOLANA_COMMITMENT=confirmed
SOLANA_PROGRAM_ID=$PROGRAM_ID
SOLANA_TOKEN_MINT=<criar ou informar a mint SPL de teste>
SOLANA_PROTOCOL_AUTHORITY=$AUTHORITY
SOLANA_PROTOCOL_TREASURY=$TREASURY
=================================

Não compartilhe os arquivos de keypair. Compartilhe apenas o Program ID acima.
OUTPUT
