<?php

namespace App\Services\Rescue;

use App\Enums\SurplusStatus;
use App\Enums\TradeStatus;
use App\Models\RescueProof;
use App\Models\Trade;
use App\Models\User;
use App\Services\Blockchain\ProtocolConfigService;
use App\Services\Blockchain\SolanaRpcClient;
use App\Services\Blockchain\SolanaTransactionVerifier;
use App\Support\Base58;
use App\UserRole;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RescueProofService
{
    private const SYSTEM_PROGRAM = '11111111111111111111111111111111';

    private const RESCUE_SEED = 'foodrescue_rescue';

    private const STATE_SIZE = 146;

    public function __construct(
        private readonly SolanaRpcClient $rpc,
        private readonly ProtocolConfigService $protocol,
    ) {}

    /** @return array<string,mixed> */
    public function prepare(User $ngo, Trade $trade): array
    {
        $trade = $trade->loadMissing(['producer', 'buyer', 'surplusLot', 'shippingRequest.selectedOffer.carrier', 'rescueProof']);
        $this->assertCanCreate($ngo, $trade);
        abort_if($trade->rescueProof !== null, 409, 'O Proof of Rescue deste trade já foi confirmado.');

        $protocol = $this->protocol->official();
        $ngoWallet = $this->requiredWallet($ngo, 'instituição social');
        $producerWallet = $this->requiredWallet($trade->producer, 'produtor');
        $carrier = $trade->shippingRequest?->selectedOffer?->carrier;
        $carrierWallet = $carrier ? $this->requiredWallet($carrier, 'transportadora') : null;
        $metadata = $this->metadata($trade);
        $metadataHash = hash('sha256', json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $instruction = chr(5)
            .pack('P', $trade->id)
            .($carrierWallet ? Base58::decode($carrierWallet) : str_repeat("\0", 32))
            .hex2bin($metadataHash);

        return [
            'program_id' => $protocol->program_id,
            'trade_id' => $trade->id,
            'proof_state_size' => self::STATE_SIZE,
            'metadata_hash' => $metadataHash,
            'metadata' => $metadata,
            'wallets' => [
                'ngo' => $ngoWallet,
                'producer' => $producerWallet,
                'carrier' => $carrierWallet,
                'protocol_authority' => $protocol->authority_wallet,
            ],
            'pda_seeds' => [
                'rescue' => [self::RESCUE_SEED, base64_encode(pack('P', $trade->id))],
                'encoding' => 'utf8_string_then_base64_u64_le',
            ],
            'instruction' => [
                'data_base64' => base64_encode($instruction),
                'accounts' => [
                    ['name' => 'ngo', 'pubkey' => $ngoWallet, 'signer' => true, 'writable' => true],
                    ['name' => 'producer', 'pubkey' => $producerWallet, 'signer' => true, 'writable' => false],
                    ['name' => 'protocol_authority', 'pubkey' => $protocol->authority_wallet, 'signer' => true, 'writable' => false],
                    ['name' => 'protocol_config', 'pubkey' => $protocol->config_pda, 'signer' => false, 'writable' => false],
                    ['name' => 'rescue_proof_pda', 'derived' => true, 'signer' => false, 'writable' => true],
                    ['name' => 'system_program', 'pubkey' => self::SYSTEM_PROGRAM, 'signer' => false, 'writable' => false],
                ],
            ],
        ];
    }

    /** @param array{signature:string,proof_pda:string} $data */
    public function confirm(User $ngo, Trade $trade, array $data): RescueProof
    {
        return DB::transaction(function () use ($ngo, $trade, $data): RescueProof {
            $locked = Trade::whereKey($trade->id)->lockForUpdate()
                ->with(['producer', 'buyer', 'surplusLot', 'shippingRequest.selectedOffer.carrier', 'rescueProof'])
                ->firstOrFail();
            $prepared = $this->prepare($ngo, $locked);
            $this->assertTransaction($data['signature'], $prepared['program_id'], $data['proof_pda'], [
                $prepared['wallets']['ngo'],
                $prepared['wallets']['producer'],
            ]);
            $state = $this->readProof($data['proof_pda'], $prepared);
            $status = $this->rpcStatus($data['signature']);

            $proof = RescueProof::create([
                'trade_id' => $locked->id,
                'program_id' => $prepared['program_id'],
                'proof_pda' => $data['proof_pda'],
                'signature' => $data['signature'],
                'slot' => $status['slot'],
                'metadata_hash' => $prepared['metadata_hash'],
                'confirmed_at' => now(),
                'metadata' => $prepared['metadata'],
            ]);

            if (in_array($locked->status, [TradeStatus::Delivered, TradeStatus::ProofPending], true)) {
                $locked->update([
                    'status' => TradeStatus::Completed,
                    'completed_at' => now(),
                ]);
                $locked->surplusLot()->update(['status' => SurplusStatus::Donated]);
            }

            return $proof->load('trade');
        }, 3);
    }

    private function assertCanCreate(User $ngo, Trade $trade): void
    {
        abort_unless($trade->is_donation, 409, 'Proof of Rescue só existe para operações de doação.');
        abort_unless($trade->buyer_id === $ngo->id && $ngo->hasRole(UserRole::Ngo->value), 403);
        abort_unless(in_array($trade->status, [TradeStatus::Delivered, TradeStatus::ProofPending, TradeStatus::Completed], true), 409, 'A doação precisa estar entregue antes do Proof of Rescue.');
        if (BigDecimal::of($trade->shipping_amount)->isPositive()) {
            abort_unless(in_array($trade->status, [TradeStatus::ProofPending, TradeStatus::Completed], true), 409, 'O frete precisa estar liquidado antes do Proof of Rescue.');
        }
    }

    private function requiredWallet(User $user, string $actor): string
    {
        abort_if(blank($user->solana_wallet_address), 422, 'A wallet Solana do '.$actor.' não foi cadastrada.');
        abort_if($user->solana_wallet_verified_at === null, 422, 'A wallet Solana do '.$actor.' ainda não foi verificada.');

        return (string) $user->solana_wallet_address;
    }

    /** @return array<string,mixed> */
    private function metadata(Trade $trade): array
    {
        $lot = $trade->surplusLot;

        return [
            'version' => 1,
            'trade_id' => $trade->id,
            'surplus_lot_id' => $lot->id,
            'product_id' => $lot->agricultural_product_id,
            'quantity' => (string) $lot->quantity,
            'unit' => (string) $lot->unit,
            'producer_id' => $trade->producer_id,
            'ngo_id' => $trade->buyer_id,
            'carrier_id' => $trade->shippingRequest?->selectedOffer?->carrier_id,
            'shipping_amount' => (string) $trade->shipping_amount,
        ];
    }

    private function assertTransaction(string $signature, string $programId, string $proofPda, array $signers): void
    {
        app(SolanaTransactionVerifier::class)->verify($signature, $programId, $proofPda, 5, [
            ...$signers,
            $this->protocol->official()->authority_wallet,
        ]);
    }

    /** @return array<string,mixed> */
    private function readProof(string $proofPda, array $prepared): array
    {
        try {
            $account = $this->rpc->accountInfo($proofPda);
        } catch (RuntimeException $exception) {
            abort(502, $exception->getMessage());
        }
        abort_unless(($account['owner'] ?? null) === $prepared['program_id'], 422, 'O Proof of Rescue não pertence ao programa FoodRescue.');
        $encoded = data_get($account, 'data.0');
        abort_unless(is_string($encoded), 422, 'Estado do Proof of Rescue inválido.');
        $raw = base64_decode($encoded, true);
        abort_unless(is_string($raw) && strlen($raw) === self::STATE_SIZE, 422, 'Tamanho do Proof of Rescue inválido.');
        abort_unless(ord($raw[0]) === 1, 422, 'Versão do Proof of Rescue inválida.');
        $tradeId = unpack('Pvalue', substr($raw, 2, 8))['value'] ?? null;
        abort_unless((int) $tradeId === (int) $prepared['trade_id'], 422, 'O Proof of Rescue referencia outro trade.');
        abort_unless(Base58::encode(substr($raw, 10, 32)) === $prepared['wallets']['producer'], 422, 'Produtor do Proof of Rescue divergente.');
        abort_unless(Base58::encode(substr($raw, 42, 32)) === $prepared['wallets']['ngo'], 422, 'NGO do Proof of Rescue divergente.');
        $carrier = Base58::encode(substr($raw, 74, 32));
        $expectedCarrier = $prepared['wallets']['carrier'];
        if ($expectedCarrier === null) {
            abort_unless(substr($raw, 74, 32) === str_repeat("\0", 32), 422, 'Carrier inesperado no Proof of Rescue.');
        } else {
            abort_unless($carrier === $expectedCarrier, 422, 'Carrier do Proof of Rescue divergente.');
        }
        abort_unless(bin2hex(substr($raw, 106, 32)) === $prepared['metadata_hash'], 422, 'Hash de metadados do Proof of Rescue divergente.');

        return ['trade_id' => (int) $tradeId];
    }

    /** @return array<string,mixed> */
    private function rpcStatus(string $signature): array
    {
        try {
            return $this->rpc->signatureStatus($signature);
        } catch (RuntimeException $exception) {
            abort(502, $exception->getMessage());
        }
    }
}
