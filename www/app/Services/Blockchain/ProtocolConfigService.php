<?php

namespace App\Services\Blockchain;

use App\Models\BlockchainProtocolConfig;
use App\Support\Base58;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProtocolConfigService
{
    private const SYSTEM_PROGRAM = '11111111111111111111111111111111';

    private const PROTOCOL_SEED = 'foodrescue_protocol';

    private const STATE_SIZE = 98;

    public function __construct(private readonly SolanaRpcClient $rpc) {}

    /** @param array{config_pda:string} $data @return array<string,mixed> */
    public function prepareInitialization(array $data): array
    {
        $programId = $this->configuredAddress('program_id');
        $mint = $this->configuredAddress('token_mint');
        $authority = $this->configuredAddress('protocol_authority');
        $treasury = $this->configuredAddress('protocol_treasury');

        return [
            'cluster' => (string) config('services.solana.cluster'),
            'rpc_url' => (string) config('services.solana.rpc_url'),
            'program_id' => $programId,
            'mint' => $mint,
            'authority_wallet' => $authority,
            'treasury_wallet' => $treasury,
            'config_pda' => $data['config_pda'],
            'pda_seeds' => [
                'protocol' => [
                    ['type' => 'utf8', 'value' => self::PROTOCOL_SEED],
                    ['type' => 'pubkey', 'value' => $authority],
                ],
            ],
            'initialize_instruction' => [
                'data_base64' => base64_encode(chr(2).Base58::decode($treasury)),
                'accounts' => [
                    ['name' => 'authority', 'pubkey' => $authority, 'signer' => true, 'writable' => true],
                    ['name' => 'protocol_config', 'pubkey' => $data['config_pda'], 'signer' => false, 'writable' => true],
                    ['name' => 'mint', 'pubkey' => $mint, 'signer' => false, 'writable' => false],
                    ['name' => 'system_program', 'pubkey' => self::SYSTEM_PROGRAM, 'signer' => false, 'writable' => false],
                ],
            ],
        ];
    }

    /** @param array{signature:string,config_pda:string} $data */
    public function confirmInitialization(array $data): BlockchainProtocolConfig
    {
        return DB::transaction(function () use ($data): BlockchainProtocolConfig {
            $programId = $this->configuredAddress('program_id');
            $authority = $this->configuredAddress('protocol_authority');
            $treasury = $this->configuredAddress('protocol_treasury');
            $mint = $this->configuredAddress('token_mint');

            $this->assertTransaction($data['signature'], $programId, $data['config_pda']);
            $state = $this->readState($data['config_pda'], $programId);

            abort_unless($state['authority_wallet'] === $authority, 422, 'Authority on-chain diferente da configurada.');
            abort_unless($state['treasury_wallet'] === $treasury, 422, 'Treasury on-chain diferente da configurada.');
            abort_unless($state['mint'] === $mint, 422, 'Mint on-chain diferente da configurada.');

            try {
                $status = $this->rpc->signatureStatus($data['signature']);
            } catch (RuntimeException $exception) {
                abort(502, $exception->getMessage());
            }

            return BlockchainProtocolConfig::query()->updateOrCreate(
                ['cluster' => (string) config('services.solana.cluster'), 'program_id' => $programId],
                [
                    'authority_wallet' => $authority,
                    'treasury_wallet' => $treasury,
                    'mint' => $mint,
                    'config_pda' => $data['config_pda'],
                    'version' => $state['version'],
                    'confirmed_at' => now(),
                ],
            );
        }, 3);
    }

    public function official(): BlockchainProtocolConfig
    {
        $config = BlockchainProtocolConfig::query()
            ->where('cluster', (string) config('services.solana.cluster'))
            ->where('program_id', $this->configuredAddress('program_id'))
            ->first();

        abort_if($config === null || $config->confirmed_at === null, 503, 'ProtocolConfig do FoodRescue ainda não foi inicializado/confirmado na Solana.');

        return $config;
    }

    /** @return array{version:int,bump:int,authority_wallet:string,treasury_wallet:string,mint:string} */
    private function readState(string $configPda, string $programId): array
    {
        try {
            $account = $this->rpc->accountInfo($configPda);
        } catch (RuntimeException $exception) {
            abort(502, $exception->getMessage());
        }
        abort_unless(($account['owner'] ?? null) === $programId, 422, 'ProtocolConfig não pertence ao programa FoodRescue.');

        $encoded = data_get($account, 'data.0');
        $binary = is_string($encoded) ? base64_decode($encoded, true) : false;
        abort_unless(is_string($binary) && strlen($binary) === self::STATE_SIZE, 422, 'ProtocolConfig on-chain inválido.');

        $version = ord($binary[0]);
        $bump = ord($binary[1]);
        abort_unless($version === 1, 422, 'Versão do ProtocolConfig incompatível.');

        return [
            'version' => $version,
            'bump' => $bump,
            'authority_wallet' => Base58::encode(substr($binary, 2, 32)),
            'treasury_wallet' => Base58::encode(substr($binary, 34, 32)),
            'mint' => Base58::encode(substr($binary, 66, 32)),
        ];
    }

    private function assertTransaction(string $signature, string $programId, string $configPda): void
    {
        app(SolanaTransactionVerifier::class)->verify($signature, $programId, $configPda, 2, [$this->configuredAddress('protocol_authority')]);
    }

    private function configuredAddress(string $key): string
    {
        $value = (string) config('services.solana.'.$key);
        abort_if($value === '', 503, 'Configuração SOLANA_'.strtoupper($key).' ausente.');
        try {
            abort_unless(strlen(Base58::decode($value)) === 32, 503, 'Configuração Solana inválida.');
        } catch (\Throwable) {
            abort(503, 'Configuração Solana inválida.');
        }

        return $value;
    }
}
