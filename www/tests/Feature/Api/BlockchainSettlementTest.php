<?php

namespace Tests\Feature\Api;

use App\Enums\SurplusStatus;
use App\Enums\TradeStatus;
use App\Models\BlockchainProtocolConfig;
use App\Models\BlockchainTradeAccount;
use App\Models\SurplusLot;
use App\Models\Trade;
use App\Models\User;
use App\Services\Logistics\TradeDelivery;
use App\Services\Logistics\TradeLogistics;
use App\Services\Marketplace\SurplusMarketplace;
use App\Support\Base58;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BlockchainSettlementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const BUYER = '6nqeg9Lx5wAY9iPqYdvboy7zepoaoMUnGzn8Gc7Wtqrw';

    private const PRODUCER = 'BWdqpqvwyQTdBiAfez5P4rwTEHKp6y5QYFq33y3xquQu';

    private const PROGRAM = 'Hqw1fUFkV2fQsASAht2c8HjK8XVFcQbhaXXjTAQ1Pxwr';

    private const MINT = '7yVZR9eQEqHoX73MDXgA7XrU3ukPGHWoHvaqcSR8KBpJ';

    private const TRADE_PDA = '4CvhuF9Neg8EEgyLB76gP45KtdYWoGCrwojLgFJZJ8Z2';

    private const VAULT = '98TbfsxVicCsXGrqKLEvNUppsiAjHg5tD9yvnmNKTtN2';

    private const PROTOCOL_CONFIG = 'ActKxMmsJW2tAqqL46LgTQ494Z3nfS4Y8ccPmhPXf1bQ';

    private const AUTHORITY = '2khEXeqcxVDREV9y63Bii9vFtPEpqpJwZHXfXMuEjciV';

    private const TREASURY = '2reRA5JzX4F6yaxrLgPoSs3VhLJuqWis1ph8Y1oJZEJj';

    private const SIGNATURE = '2AXDGYSE4f2sz7tvMMzyHvUfcoJmxudvdhBcmiUSo6ijwfYmfZYsKRxboQMPh3R4kUhXRVdtSXFXMheka4Rc4P2';

    public function test_prepare_settlement_exposes_verified_split_and_destination_owners(): void
    {
        [$trade, $buyer] = $this->deliveredTrade();
        $this->fakeRpc($trade, 6, '20000000001');

        Sanctum::actingAs($buyer);
        $this->postEmptyJson('/api/v1/trades/'.$trade->id.'/blockchain/settlement/prepare')
            ->assertOk()
            ->assertJsonPath('data.amounts.producer', '19600000000')
            ->assertJsonPath('data.amounts.treasury', '400000000')
            ->assertJsonPath('data.amounts.carrier', '0')
            ->assertJsonPath('data.wallets.treasury', self::TREASURY)
            ->assertJsonPath('data.settle_instruction.data_base64', base64_encode(chr(3)));
    }

    public function test_confirm_settlement_completes_trade_only_after_onchain_state_and_empty_vault_are_verified(): void
    {
        [$trade, $buyer] = $this->deliveredTrade();
        $this->fakeRpc($trade, 2, '20000000000');

        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/trades/'.$trade->id.'/blockchain/settlement/confirm', [
            'signature' => self::SIGNATURE,
        ])->assertOk()
            ->assertJsonPath('data.status', TradeStatus::Completed->value);

        $trade->refresh();
        $this->assertNotNull($trade->completed_at);
        $this->assertNotNull($trade->blockchainAccount->settled_at);
        $this->assertSame(SurplusStatus::Sold, $trade->surplusLot->fresh()->status);
        $this->assertDatabaseHas('blockchain_transactions', [
            'trade_id' => $trade->id,
            'type' => 'settle_trade',
            'signature' => self::SIGNATURE,
        ]);
    }

    /** @return array{0: Trade, 1: User} */
    public static function chainStates(): array
    {
        return array_map(fn ($state) => [$state], [0, 1, 2, 3, 255]);
    }

    #[DataProvider('chainStates')]
    public function test_settlement_confirmation_requires_the_settled_chain_state(int $state): void
    {
        [$trade, $buyer] = $this->deliveredTrade();
        Sanctum::actingAs($buyer);
        $this->fakeRpc($trade, $state, $state === 2 ? '20000000000' : '0');
        $this->postJson('/api/v1/trades/'.$trade->id.'/blockchain/settlement/confirm', ['signature' => self::SIGNATURE])
            ->assertStatus($state === 2 ? 200 : 422);
        $this->assertSame($state === 2 ? TradeStatus::Completed : TradeStatus::Delivered, $trade->fresh()->status);
    }

    private function deliveredTrade(): array
    {
        config([
            'services.solana.cluster' => 'devnet',
            'services.solana.rpc_url' => 'https://api.devnet.solana.com',
            'services.solana.commitment' => 'confirmed',
            'services.solana.program_id' => self::PROGRAM,
            'services.solana.token_mint' => self::MINT,
            'services.solana.protocol_authority' => self::AUTHORITY,
            'services.solana.protocol_treasury' => self::TREASURY,
        ]);

        BlockchainProtocolConfig::create([
            'cluster' => 'devnet', 'program_id' => self::PROGRAM, 'authority_wallet' => self::AUTHORITY,
            'treasury_wallet' => self::TREASURY, 'mint' => self::MINT, 'config_pda' => self::PROTOCOL_CONFIG,
            'version' => 1, 'confirmed_at' => now(),
        ]);

        $producer = User::factory()->withRole(UserRole::Producer)->create([
            'solana_wallet_address' => self::PRODUCER, 'solana_wallet_verified_at' => now(),
        ]);
        $buyer = User::factory()->withRole(UserRole::Buyer)->create([
            'solana_wallet_address' => self::BUYER, 'solana_wallet_verified_at' => now(),
        ]);
        $lot = SurplusLot::factory()->create(['producer_id' => $producer->id]);
        $trade = app(SurplusMarketplace::class)->buyNow($buyer, $lot);
        app(TradeLogistics::class)->buyerManaged($buyer, $trade, [
            'destination_address' => 'Av. Central, 100',
            'destination_city' => 'Campinas',
            'destination_state' => 'SP',
            'destination_country' => 'BR',
        ]);
        $trade->refresh()->update(['status' => TradeStatus::Funded, 'payment_expires_at' => null]);

        BlockchainTradeAccount::create([
            'trade_id' => $trade->id,
            'cluster' => 'devnet',
            'program_id' => self::PROGRAM,
            'mint' => self::MINT,
            'protocol_config_pda' => self::PROTOCOL_CONFIG,
            'token_decimals' => 6,
            'trade_pda' => self::TRADE_PDA,
            'vault_token_account' => self::VAULT,
            'initialized_at' => now()->subMinute(),
            'funded_at' => now(),
        ]);

        app(TradeDelivery::class)->markReadyForPickup($producer, $trade->fresh());
        app(TradeDelivery::class)->confirmPickup($buyer, $trade->fresh());
        app(TradeDelivery::class)->markDelivered($buyer, $trade->fresh());

        return [$trade->fresh(), $buyer];
    }

    private function fakeRpc(Trade $trade, int $stateStatus, string $vaultAmount): void
    {
        $vaultReads = 0;
        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($trade, $stateStatus, $vaultAmount, &$vaultReads) {
            return match ($request->data()['method'] ?? null) {
                'getSignatureStatuses' => Http::response(['jsonrpc' => '2.0', 'result' => [
                    'context' => ['slot' => 123],
                    'value' => [[
                        'slot' => 123, 'confirmations' => 1, 'err' => null,
                        'status' => ['Ok' => null], 'confirmationStatus' => 'confirmed',
                    ]],
                ], 'id' => 1]),
                'getTransaction' => Http::response(['jsonrpc' => '2.0', 'result' => [
                    'slot' => 123, 'meta' => ['err' => null],
                    'meta' => ['err' => null, 'preTokenBalances' => [[
                        'accountIndex' => 0, 'mint' => self::MINT, 'owner' => self::TRADE_PDA,
                        'uiTokenAmount' => ['amount' => '20000000000'],
                    ]]],
                    'transaction' => ['message' => [
                        'accountKeys' => [['pubkey' => self::VAULT], ['pubkey' => self::TRADE_PDA], ['pubkey' => self::BUYER, 'signer' => true]],
                        'instructions' => [['programId' => self::PROGRAM, 'accounts' => [self::TRADE_PDA], 'data' => Base58::encode(chr(3))]],
                    ]],
                    'version' => 'legacy',
                ], 'id' => 1]),
                'getAccountInfo' => $this->accountInfoResponse(
                    $request,
                    $trade,
                    $stateStatus,
                    $stateStatus === 2 ? '0' : $vaultAmount,
                ),
                default => Http::response(['jsonrpc' => '2.0', 'error' => ['message' => 'unexpected method'], 'id' => 1]),
            };
        });
    }

    private function accountInfoResponse(Request $request, Trade $trade, int $stateStatus, string $vaultAmount)
    {
        $address = $request->data()['params'][0] ?? '';
        if ($address === self::VAULT) {
            return Http::response(['jsonrpc' => '2.0', 'result' => ['value' => [
                'owner' => 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA',
                'data' => ['parsed' => ['info' => [
                    'owner' => self::TRADE_PDA, 'mint' => self::MINT,
                    'tokenAmount' => ['amount' => $vaultAmount, 'decimals' => 6, 'uiAmountString' => '0'],
                ]]], 'lamports' => 2039280,
            ]], 'id' => 1]);
        }

        $state = chr(2).chr(254).chr(253)
            .pack('P', $trade->id)
            .Base58::decode(self::BUYER)
            .Base58::decode(self::PRODUCER)
            .str_repeat("\0", 32)
            .Base58::decode(self::MINT)
            .Base58::decode(self::VAULT)
            .Base58::decode(self::PROTOCOL_CONFIG)
            .pack('P', 20000000000)
            .pack('P', 0)
            .pack('P', 400000000)
            .pack('P', 20000000000)
            .pack('P', 0)
            .chr($stateStatus);

        return Http::response(['jsonrpc' => '2.0', 'result' => ['value' => [
            'owner' => self::PROGRAM,
            'data' => [base64_encode($state), 'base64'],
            'lamports' => 2500000,
        ]], 'id' => 1]);
    }
}
