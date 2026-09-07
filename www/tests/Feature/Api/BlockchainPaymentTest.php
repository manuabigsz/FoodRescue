<?php

namespace Tests\Feature\Api;

use App\Enums\TradeStatus;
use App\Models\BlockchainProtocolConfig;
use App\Models\BlockchainTradeAccount;
use App\Models\SurplusLot;
use App\Models\Trade;
use App\Models\User;
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

class BlockchainPaymentTest extends TestCase
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

    private const SIGNATURE = 'AKAh9LUoWFG2sxAMotzmLNpKwPTCiG6Q4YTwAinZMnkvYKPAKVPwYSfoQDp8XLKWzpbCNx66XB1BrcD1ZUPqU39';

    public static function confirmationStates(): array
    {
        $cases = [];
        foreach ([0, 1, 2, 3, 255] as $state) {
            foreach (['initialize', 'funding'] as $action) {
                $cases[$action.':'.$state] = [$action, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('confirmationStates')]
    public function test_confirmation_rejects_every_unexpected_chain_state(string $action, int $state): void
    {
        [$trade, $buyer] = $this->waitingPaymentTrade();
        if ($action === 'funding') {
            BlockchainTradeAccount::create([
                'trade_id' => $trade->id, 'cluster' => 'devnet', 'program_id' => self::PROGRAM,
                'mint' => self::MINT, 'token_decimals' => 6, 'trade_pda' => self::TRADE_PDA,
                'vault_token_account' => self::VAULT, 'initialized_at' => now(),
            ]);
        }
        $tag = $action === 'initialize' ? 0 : 1;
        $this->fakeRpc($trade, $state, $action === 'funding' ? '20000000000' : '0', $tag);
        Sanctum::actingAs($buyer);
        $payload = ['signature' => self::SIGNATURE];
        if ($action === 'initialize') {
            $payload += ['trade_pda' => self::TRADE_PDA, 'vault_token_account' => self::VAULT];
        }
        $this->postJson('/api/v1/trades/'.$trade->id.'/blockchain/'.$action.'/confirm', $payload)
            ->assertStatus($state === $tag ? ($tag === 0 ? 201 : 200) : 422);
        if ($state !== $tag) {
            $this->assertSame(TradeStatus::WaitingPayment, $trade->fresh()->status);
            $this->assertDatabaseCount('blockchain_transactions', 0);
        }
    }

    public function test_prepare_returns_unsigned_instruction_spec_for_buyer_wallet(): void
    {
        [$trade, $buyer] = $this->waitingPaymentTrade();
        $this->fakeRpc(stateStatus: 0, vaultAmount: '0');

        Sanctum::actingAs($buyer);
        $this->postEmptyJson('/api/v1/trades/'.$trade->id.'/blockchain/prepare')
            ->assertOk()
            ->assertJsonPath('data.program_id', self::PROGRAM)
            ->assertJsonPath('data.mint', self::MINT)
            ->assertJsonPath('data.token_decimals', 6)
            ->assertJsonPath('data.protocol_config.pda', self::PROTOCOL_CONFIG)
            ->assertJsonPath('data.protocol_config.treasury_wallet', self::TREASURY)
            ->assertJsonPath('data.amounts.product', '20000000000')
            ->assertJsonPath('data.amounts.protocol_fee', '400000000')
            ->assertJsonPath('data.amounts.total', '20000000000')
            ->assertJsonPath('data.wallets.buyer', self::BUYER)
            ->assertJsonPath('data.wallets.producer', self::PRODUCER)
            ->assertJsonPath('data.initialize_instruction.accounts.0.signer', true);
    }

    public function test_confirm_initialize_persists_verified_pda_and_signature(): void
    {
        [$trade, $buyer] = $this->waitingPaymentTrade();
        $this->fakeRpc($trade, 0, '0');

        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/trades/'.$trade->id.'/blockchain/initialize/confirm', [
            'signature' => self::SIGNATURE,
            'trade_pda' => self::TRADE_PDA,
            'vault_token_account' => self::VAULT,
        ])->assertCreated()
            ->assertJsonPath('data.trade_pda', self::TRADE_PDA)
            ->assertJsonPath('data.vault_token_account', self::VAULT)
            ->assertJsonPath('data.transactions.0.type', 'initialize_trade');

        $this->assertDatabaseHas('blockchain_trade_accounts', [
            'trade_id' => $trade->id,
            'program_id' => self::PROGRAM,
            'mint' => self::MINT,
        ]);
    }

    public function test_confirm_funding_moves_backend_trade_to_funded_only_after_onchain_state_is_funded(): void
    {
        [$trade, $buyer] = $this->waitingPaymentTrade();
        BlockchainTradeAccount::create([
            'trade_id' => $trade->id,
            'cluster' => 'devnet',
            'program_id' => self::PROGRAM,
            'mint' => self::MINT,
            'token_decimals' => 6,
            'trade_pda' => self::TRADE_PDA,
            'vault_token_account' => self::VAULT,
            'initialized_at' => now(),
        ]);
        $this->fakeRpc($trade, 1, '20000000000');

        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/trades/'.$trade->id.'/blockchain/funding/confirm', [
            'signature' => self::SIGNATURE,
        ])->assertOk()
            ->assertJsonPath('data.status', TradeStatus::Funded->value);

        $this->assertNotNull($trade->fresh()->blockchainAccount->funded_at);

        $this->assertSame(TradeStatus::Funded, $trade->fresh()->status);
        $this->assertNull($trade->fresh()->payment_expires_at);
        $this->assertDatabaseHas('blockchain_transactions', [
            'trade_id' => $trade->id,
            'type' => 'fund_trade',
            'signature' => self::SIGNATURE,
        ]);
    }

    public function test_funding_confirmed_late_keeps_the_original_reservation_and_deadline(): void
    {
        [$trade, $buyer] = $this->waitingPaymentTrade();
        $this->fakeRpc($trade, 0, '0');
        Sanctum::actingAs($buyer);
        $path = '/api/v1/trades/'.$trade->id.'/blockchain';
        $this->postEmptyJson($path.'/prepare')->assertOk();
        $this->postJson($path.'/initialize/confirm', [
            'signature' => self::SIGNATURE, 'trade_pda' => self::TRADE_PDA, 'vault_token_account' => self::VAULT,
        ])->assertCreated();
        $this->travel(20)->minutes();
        $this->assertSame(0, app(TradeLogistics::class)->expireUnfundedTrades());
        $this->fakeRpc($trade, 1, '20000000000');
        $this->postJson($path.'/funding/confirm', ['signature' => Base58::encode(str_repeat(chr(60), 64))])
            ->assertOk()->assertJsonPath('data.status', 'funded');
        $this->assertSame('reserved', $trade->surplusLot->fresh()->status->value);
    }

    public function test_unverified_payer_cannot_prepare_financial_instructions(): void
    {
        [$trade, $buyer] = $this->waitingPaymentTrade();
        $buyer->update(['solana_wallet_verified_at' => null]);
        Sanctum::actingAs($buyer);
        $this->postEmptyJson('/api/v1/trades/'.$trade->id.'/blockchain/prepare')->assertUnprocessable();
    }

    public function test_mint_precision_cannot_silently_change_the_recorded_protocol_fee(): void
    {
        [$trade, $buyer] = $this->waitingPaymentTrade();
        $trade->update(['product_amount' => '0.000001', 'protocol_fee' => '0']);
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['value' => ['decimals' => 9]]])]);
        Sanctum::actingAs($buyer);
        $this->postEmptyJson('/api/v1/trades/'.$trade->id.'/blockchain/prepare')->assertUnprocessable();
        $this->assertNull($trade->fresh()->blockchain_preparation);
    }

    /** @return array{0: Trade, 1: User} */
    private function waitingPaymentTrade(): array
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

        $producer = User::factory()->withRole(UserRole::Producer)->create(['solana_wallet_address' => self::PRODUCER, 'solana_wallet_verified_at' => now()]);
        $lot = SurplusLot::factory()->create(['producer_id' => $producer->id]);
        $buyer = User::factory()->withRole(UserRole::Buyer)->create(['solana_wallet_address' => self::BUYER, 'solana_wallet_verified_at' => now()]);
        $trade = app(SurplusMarketplace::class)->buyNow($buyer, $lot);
        app(TradeLogistics::class)->buyerManaged($buyer, $trade, [
            'destination_address' => 'Av. Central, 100',
            'destination_city' => 'Campinas',
            'destination_state' => 'SP',
            'destination_country' => 'BR',
        ]);

        return [$trade->fresh(), $buyer];
    }

    private function fakeRpc(?Trade $trade = null, int $stateStatus = 0, string $vaultAmount = '0', ?int $tag = null): void
    {
        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($trade, $stateStatus, $vaultAmount, $tag) {
            $method = $request->data()['method'] ?? null;

            return match ($method) {
                'getTokenSupply' => Http::response(['jsonrpc' => '2.0', 'result' => [
                    'context' => ['slot' => 100],
                    'value' => ['amount' => '1000000000000', 'decimals' => 6, 'uiAmount' => 1000000, 'uiAmountString' => '1000000'],
                ], 'id' => 1]),
                'getSignatureStatuses' => Http::response(['jsonrpc' => '2.0', 'result' => [
                    'context' => ['slot' => 123],
                    'value' => [[
                        'slot' => 123, 'confirmations' => 1, 'err' => null,
                        'status' => ['Ok' => null], 'confirmationStatus' => 'confirmed',
                    ]],
                ], 'id' => 1]),
                'getTransaction' => Http::response(['jsonrpc' => '2.0', 'result' => [
                    'slot' => 123,
                    'meta' => ['err' => null],
                    'transaction' => ['message' => [
                        'accountKeys' => [
                            ['pubkey' => self::TRADE_PDA],
                            ['pubkey' => self::BUYER, 'signer' => true],
                            ['pubkey' => self::AUTHORITY, 'signer' => true],
                        ],
                        'instructions' => [['programId' => self::PROGRAM, 'accounts' => [self::TRADE_PDA], 'data' => Base58::encode((($tag ?? $stateStatus) === 0 ? chr(0).str_repeat("\0", 104) : chr(1)))]],
                    ]],
                    'version' => 'legacy',
                ], 'id' => 1]),
                'getAccountInfo' => $this->accountInfoResponse($request, $trade, $stateStatus, $vaultAmount),
                default => Http::response(['jsonrpc' => '2.0', 'error' => ['message' => 'unexpected method'], 'id' => 1], 200),
            };
        });
    }

    private function accountInfoResponse(Request $request, ?Trade $trade, int $stateStatus, string $vaultAmount)
    {
        $address = $request->data()['params'][0] ?? '';
        if ($address === self::VAULT) {
            return Http::response(['jsonrpc' => '2.0', 'result' => ['value' => [
                'owner' => 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA',
                'data' => ['parsed' => ['info' => [
                    'owner' => self::TRADE_PDA,
                    'mint' => self::MINT,
                    'tokenAmount' => ['amount' => $vaultAmount, 'decimals' => 6, 'uiAmountString' => '0'],
                ]]],
                'lamports' => 2039280,
            ]], 'id' => 1]);
        }

        if (! $trade) {
            return Http::response(['jsonrpc' => '2.0', 'result' => ['value' => null], 'id' => 1]);
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
            .pack('P', $trade->payment_expires_at->getTimestamp())
            .chr($stateStatus);

        return Http::response(['jsonrpc' => '2.0', 'result' => ['value' => [
            'owner' => self::PROGRAM,
            'data' => [base64_encode($state), 'base64'],
            'lamports' => 2500000,
        ]], 'id' => 1]);
    }
}
