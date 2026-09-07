<?php

namespace App\Services\Blockchain;

use App\Enums\BlockchainTransactionStatus;
use App\Enums\BlockchainTransactionType;
use App\Enums\TradeStatus;
use App\Models\BlockchainTransaction;
use App\Models\Trade;
use App\Models\User;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BlockchainDeliveryService
{
    private const OPERATIONS = [
        'ready-for-pickup' => [
            'tag' => 6,
            'from_db' => TradeStatus::Funded,
            'to_chain' => BlockchainPaymentService::STATE_READY_FOR_PICKUP,
            'type' => BlockchainTransactionType::MarkReadyForPickup,
        ],
        'pickup' => [
            'tag' => 7,
            'from_db' => TradeStatus::ReadyForPickup,
            'to_chain' => BlockchainPaymentService::STATE_IN_TRANSIT,
            'type' => BlockchainTransactionType::ConfirmPickup,
        ],
        'delivered' => [
            'tag' => 8,
            'from_db' => TradeStatus::InTransit,
            'to_chain' => BlockchainPaymentService::STATE_DELIVERED,
            'type' => BlockchainTransactionType::MarkDelivered,
        ],
    ];

    public function __construct(
        private readonly SolanaTransactionVerifier $verifier,
        private readonly SolanaRpcClient $rpc,
    ) {}

    /** @return array<string, mixed> */
    public function prepare(User $actor, Trade $trade, string $operation): array
    {
        $definition = $this->definition($operation);
        $trade = $trade->loadMissing(['buyer', 'producer', 'shippingRequest.selectedOffer.carrier', 'blockchainAccount']);
        abort_unless($trade->status === $definition['from_db'], 409, 'O trade não está no estado operacional esperado.');
        $this->assertActor($actor, $trade, $operation);
        $account = $trade->blockchainAccount;
        abort_if($account === null || $account->funded_at === null, 409, 'O trade ainda não possui escrow financiado on-chain.');

        $wallet = $this->actorWallet($actor);

        return [
            'cluster' => $account->cluster,
            'rpc_url' => (string) config('services.solana.rpc_url'),
            'commitment' => (string) config('services.solana.commitment'),
            'program_id' => $account->program_id,
            'trade_id' => $trade->id,
            'trade_pda' => $account->trade_pda,
            'wallet' => $wallet,
            'from_status' => $definition['from_db']->value,
            'to_status' => $this->databaseStatus($operation)->value,
            'instruction' => [
                'data_base64' => base64_encode(chr($definition['tag'])),
                'accounts' => [
                    ['name' => 'actor', 'pubkey' => $wallet, 'signer' => true, 'writable' => false],
                    ['name' => 'trade_pda', 'pubkey' => $account->trade_pda, 'signer' => false, 'writable' => true],
                ],
            ],
        ];
    }

    /** @param array{signature:string} $data */
    public function confirm(User $actor, Trade $trade, string $operation, array $data): Trade
    {
        $definition = $this->definition($operation);
        abort_if(blank($data['signature'] ?? null), 422, 'A confirmação on-chain exige uma assinatura.');

        return DB::transaction(function () use ($actor, $trade, $operation, $data, $definition): Trade {
            $locked = Trade::whereKey($trade->id)->lockForUpdate()
                ->with(['buyer', 'producer', 'shippingRequest.selectedOffer.carrier', 'blockchainAccount'])
                ->firstOrFail();
            $prepared = $this->prepare($actor, $locked, $operation);
            $this->verifier->verify($data['signature'], $prepared['program_id'], $prepared['trade_pda'], $definition['tag'], [$prepared['wallet']]);

            try {
                $status = $this->rpc->signatureStatus($data['signature']);
            } catch (RuntimeException $exception) {
                abort(502, $exception->getMessage());
            }

            BlockchainTransaction::create([
                'trade_id' => $locked->id,
                'type' => $definition['type'],
                'signature' => $data['signature'],
                'slot' => $status['slot'],
                'status' => BlockchainTransactionStatus::from($status['confirmationStatus']),
                'confirmed_at' => now(),
                'metadata' => ['from' => $definition['from_db']->value, 'to' => $this->databaseStatus($operation)->value],
            ]);

            $locked->update([$this->timestampColumn($operation) => now(), 'status' => $this->databaseStatus($operation)]);

            return $locked->fresh()->load(['shippingRequest.selectedOffer.carrier.roles', 'blockchainAccount.transactions']);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function definition(string $operation): array
    {
        abort_unless(isset(self::OPERATIONS[$operation]), 404, 'Operação de entrega desconhecida.');

        return self::OPERATIONS[$operation];
    }

    private function databaseStatus(string $operation): TradeStatus
    {
        return match ($operation) {
            'ready-for-pickup' => TradeStatus::ReadyForPickup,
            'pickup' => TradeStatus::InTransit,
            'delivered' => TradeStatus::Delivered,
        };
    }

    private function timestampColumn(string $operation): string
    {
        return match ($operation) {
            'ready-for-pickup' => 'ready_for_pickup_at',
            'pickup' => 'picked_up_at',
            'delivered' => 'delivered_at',
        };
    }

    private function actorWallet(User $actor): string
    {
        abort_if(blank($actor->solana_wallet_address), 422, 'A wallet Solana do responsável não foi cadastrada.');
        abort_if($actor->solana_wallet_verified_at === null, 422, 'A wallet Solana do responsável ainda não foi verificada.');

        return (string) $actor->solana_wallet_address;
    }

    private function assertActor(User $actor, Trade $trade, string $operation): void
    {
        if ($operation === 'ready-for-pickup') {
            abort_unless($actor->id === $trade->producer_id && $actor->hasRole(UserRole::Producer->value), 403);

            return;
        }

        $carrier = $trade->shippingRequest?->selectedOffer?->carrier;
        if ($carrier !== null) {
            abort_unless($actor->id === $carrier->id && $actor->hasRole(UserRole::Carrier->value), 403);

            return;
        }

        abort_unless($actor->id === $trade->buyer_id
            && $actor->hasRole($trade->is_donation ? UserRole::Ngo->value : UserRole::Buyer->value), 403);
    }
}
