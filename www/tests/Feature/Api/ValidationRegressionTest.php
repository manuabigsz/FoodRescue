<?php

namespace Tests\Feature\Api;

use App\Enums\OfferStatus;
use App\Enums\SurplusStatus;
use App\Enums\TradeStatus;
use App\Models\BlockchainTradeAccount;
use App\Models\Offer;
use App\Models\SurplusLot;
use App\Models\Trade;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use App\Services\Logistics\TradeLogistics;
use App\Services\Marketplace\SurplusMarketplace;
use App\Services\WalletVerificationService;
use App\Support\Base58;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValidationRegressionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function deliveryStates(): array
    {
        $cases = [];
        foreach ([false, true] as $donation) {
            foreach (TradeStatus::cases() as $status) {
                foreach (['ready-for-pickup' => TradeStatus::Funded, 'pickup' => TradeStatus::ReadyForPickup, 'delivered' => TradeStatus::InTransit] as $action => $required) {
                    $cases[($donation ? 'donation' : 'sale').':'.$status->value.':'.$action] = [$donation, $status, $action, $status === $required];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('deliveryStates')]
    public function test_delivery_state_matrix(bool $donation, TradeStatus $status, string $action, bool $allowed): void
    {
        $lot = SurplusLot::factory()->create(['status' => SurplusStatus::Reserved]);
        $buyer = User::factory()->withRole($donation ? UserRole::Ngo : UserRole::Buyer)->create();
        $trade = Trade::create([
            'surplus_lot_id' => $lot->id, 'producer_id' => $lot->producer_id, 'buyer_id' => $buyer->id,
            'product_amount' => $donation ? '0' : '100', 'protocol_fee' => $donation ? '0' : '2',
            'status' => $status, 'is_donation' => $donation,
        ]);
        Sanctum::actingAs($action === 'ready-for-pickup' ? $lot->producer : $buyer);
        $this->postEmptyJson('/api/v1/trades/'.$trade->id.'/delivery/'.$action)->assertStatus($allowed ? 200 : 409);
        if (! $allowed) {
            $this->assertSame($status, $trade->fresh()->status);
        }
    }

    public static function lotStates(): array
    {
        return array_map(fn ($state) => [$state], SurplusStatus::cases());
    }

    #[DataProvider('lotStates')]
    public function test_buy_now_state_matrix(SurplusStatus $state): void
    {
        $lot = SurplusLot::factory()->create(['status' => $state]);
        Sanctum::actingAs(User::factory()->withRole(UserRole::Buyer)->create());
        $this->postEmptyJson('/api/v1/surplus/'.$lot->id.'/buy-now')->assertStatus($state === SurplusStatus::Open ? 201 : 409);
    }

    public static function offerStates(): array
    {
        return array_map(fn ($state) => [$state], OfferStatus::cases());
    }

    #[DataProvider('offerStates')]
    public function test_offer_acceptance_state_matrix(OfferStatus $state): void
    {
        $lot = SurplusLot::factory()->create();
        $offer = Offer::factory()->create(['surplus_lot_id' => $lot->id, 'status' => $state]);
        Sanctum::actingAs($lot->producer);
        $this->postEmptyJson('/api/v1/offers/'.$offer->id.'/accept')->assertStatus($state === OfferStatus::Pending ? 201 : 409);
    }

    public function test_expired_trade_allows_a_new_sale_and_latest_relationship_returns_it(): void
    {
        $lot = SurplusLot::factory()->create();
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();
        $old = app(SurplusMarketplace::class)->buyNow($buyer, $lot);
        $old->update(['status' => TradeStatus::WaitingPayment, 'payment_expires_at' => now()->subSecond()]);
        $this->assertSame(1, app(TradeLogistics::class)->expireUnfundedTrades());
        $new = app(SurplusMarketplace::class)->buyNow($buyer, $lot->fresh());
        $this->assertSame($new->id, $lot->fresh()->trade->id);
        $this->assertSame(2, $lot->trades()->count());
    }

    public function test_timeout_preserves_a_prepared_or_initialized_escrow_reservation(): void
    {
        foreach ([false, true] as $initialized) {
            $lot = SurplusLot::factory()->create();
            $buyer = User::factory()->withRole(UserRole::Buyer)->create();
            $trade = app(SurplusMarketplace::class)->buyNow($buyer, $lot);
            $trade->update(['status' => TradeStatus::WaitingPayment, 'payment_expires_at' => now()->subMinute(), 'blockchain_preparation' => $initialized ? null : ['trade_id' => $trade->id]]);
            if ($initialized) {
                BlockchainTradeAccount::create([
                    'trade_id' => $trade->id, 'cluster' => 'devnet', 'program_id' => 'program', 'mint' => 'mint',
                    'token_decimals' => 6, 'trade_pda' => 'trade', 'vault_token_account' => 'vault', 'initialized_at' => now(),
                ]);
            }
            $this->assertSame(0, app(TradeLogistics::class)->expireUnfundedTrades());
            $this->assertSame(SurplusStatus::Reserved, $lot->fresh()->status);
            Sanctum::actingAs($buyer);
            $this->postJson('/api/v1/trades/'.$trade->id.'/cancel', ['reason' => 'Unreported funding'])->assertConflict();
        }
    }

    public function test_reconciliation_releases_a_preparation_after_grace_when_chain_has_no_trade_state(): void
    {
        config(['accounts.blockchain_reconciliation_grace_minutes' => 30]);
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => []])]);
        $lot = SurplusLot::factory()->create();
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();
        $trade = app(SurplusMarketplace::class)->buyNow($buyer, $lot);
        $trade->update([
            'status' => TradeStatus::WaitingPayment,
            'payment_expires_at' => now()->subMinutes(31),
            'blockchain_preparation' => ['trade_id' => $trade->id, 'program_id' => 'program'],
        ]);

        $this->assertSame(1, app(TradeLogistics::class)->expireUnfundedTrades());
        $this->assertSame(TradeStatus::Expired, $trade->fresh()->status);
        $this->assertSame(SurplusStatus::Open, $lot->fresh()->status);
    }

    public function test_reconciliation_keeps_a_preparation_when_rpc_is_unavailable(): void
    {
        config(['accounts.blockchain_reconciliation_grace_minutes' => 30]);
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['message' => 'temporary outage']])]);
        $lot = SurplusLot::factory()->create();
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();
        $trade = app(SurplusMarketplace::class)->buyNow($buyer, $lot);
        $trade->update([
            'status' => TradeStatus::WaitingPayment,
            'payment_expires_at' => now()->subMinutes(31),
            'blockchain_preparation' => ['trade_id' => $trade->id, 'program_id' => 'program'],
        ]);

        $this->assertSame(0, app(TradeLogistics::class)->expireUnfundedTrades());
        $this->assertSame(TradeStatus::WaitingPayment, $trade->fresh()->status);
        $this->assertSame(SurplusStatus::Reserved, $lot->fresh()->status);
    }

    public function test_expiration_does_not_reopen_perished_surplus(): void
    {
        $lot = SurplusLot::factory()->create();
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();
        $trade = app(SurplusMarketplace::class)->buyNow($buyer, $lot);
        $lot->update(['available_until' => now()->subSecond()]);
        $trade->update(['status' => TradeStatus::WaitingPayment, 'payment_expires_at' => now()->subSecond()]);
        app(TradeLogistics::class)->expireUnfundedTrades();
        $this->assertSame(SurplusStatus::Expired, $lot->fresh()->status);
    }

    public function test_financial_totals_preserve_six_decimal_places_at_database_limits(): void
    {
        $lot = SurplusLot::factory()->create(['asking_price' => '999999999999.123456']);
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();
        $trade = app(SurplusMarketplace::class)->buyNow($buyer, $lot);
        $this->assertSame('19999999999.982469', $trade->protocol_fee);
        $trade->update(['status' => TradeStatus::Completed, 'shipping_amount' => '0.000001']);
        $dashboard = app(DashboardService::class)->buyer($buyer);
        $this->assertSame('999999999999.123457', $dashboard['summary']['total_spend']);
        $this->assertSame('979999999999.140987', app(DashboardService::class)->producer($lot->producer)['summary']['recovered_revenue']);
    }

    public function test_maximum_price_filter_works_without_minimum_price(): void
    {
        SurplusLot::factory()->create();
        Sanctum::actingAs(User::factory()->withRole(UserRole::Buyer)->create());
        $this->getJson('/api/v1/surplus?max_price=25000')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_amounts_that_would_round_in_postgres_are_rejected(): void
    {
        $lot = SurplusLot::factory()->create();
        Sanctum::actingAs($lot->producer);
        $this->patchJson('/api/v1/surplus/'.$lot->id, ['asking_price' => '0.0000001', 'quantity' => '0.0001'])
            ->assertUnprocessable()->assertJsonValidationErrors(['asking_price', 'quantity']);
    }

    public function test_zero_value_commercial_purchase_is_rejected_atomically(): void
    {
        $lot = SurplusLot::factory()->create(['asking_price' => '0', 'minimum_price' => '0']);
        Sanctum::actingAs(User::factory()->withRole(UserRole::Buyer)->create());
        $this->postEmptyJson('/api/v1/surplus/'.$lot->id.'/buy-now')->assertUnprocessable();
        $this->assertSame(SurplusStatus::Open, $lot->fresh()->status);
        $this->assertDatabaseCount('trades', 0);
    }

    public function test_scheduler_expires_open_lots_and_pending_offers(): void
    {
        $lot = SurplusLot::factory()->create(['available_until' => now()->subSecond()]);
        $offer = Offer::factory()->create(['surplus_lot_id' => $lot->id, 'status' => OfferStatus::Pending]);
        $reserved = SurplusLot::factory()->create(['status' => SurplusStatus::Reserved, 'available_until' => now()->subSecond()]);
        $this->artisan('foodrescue:expire-unfunded-trades')->assertSuccessful();
        $this->assertSame(SurplusStatus::Expired, $lot->fresh()->status);
        $this->assertSame(OfferStatus::Expired, $offer->fresh()->status);
        $this->assertSame(SurplusStatus::Reserved, $reserved->fresh()->status);
    }

    public function test_expired_offer_does_not_block_a_new_proposal_by_the_same_buyer(): void
    {
        $lot = SurplusLot::factory()->create();
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();
        $old = Offer::factory()->create(['surplus_lot_id' => $lot->id, 'buyer_id' => $buyer->id, 'expires_at' => now()->subSecond(), 'status' => OfferStatus::Pending]);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/surplus/'.$lot->id.'/offers', ['amount' => '100'])->assertCreated();
        $this->assertSame(OfferStatus::Expired, $old->fresh()->status);
    }

    public function test_direct_wallet_change_invalidates_existing_verification(): void
    {
        $user = User::factory()->create(['solana_wallet_address' => Base58::encode(random_bytes(32)), 'solana_wallet_verified_at' => now()]);
        $user->update(['solana_wallet_address' => Base58::encode(random_bytes(32))]);
        $this->assertNull($user->fresh()->solana_wallet_verified_at);
    }

    public static function invalidChallenges(): array
    {
        return [['expired'], ['nonce'], ['wallet'], ['replay'], ['different user']];
    }

    #[DataProvider('invalidChallenges')]
    public function test_wallet_challenges_reject_expiry_tampering_and_replay(string $scenario): void
    {
        $user = User::factory()->withRole(UserRole::Buyer)->create();
        $keys = sodium_crypto_sign_keypair();
        $service = app(WalletVerificationService::class);
        $challenge = $service->createUserChallenge($user, Base58::encode(sodium_crypto_sign_publickey($keys)));
        $message = $scenario === 'nonce' ? $challenge['message'].'tampered nonce' : $challenge['message'];
        if ($scenario === 'wallet') {
            $keys = sodium_crypto_sign_keypair();
        }
        $signature = base64_encode(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($keys)));
        if ($scenario === 'expired') {
            $this->travel(6)->minutes();
        }
        if ($scenario === 'replay') {
            $service->verifyForUser($user, $challenge['id'], $signature);
        }
        if ($scenario === 'different user') {
            $user = User::factory()->withRole(UserRole::Buyer)->create();
        }
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/auth/wallet/change/verify', ['challenge_id' => $challenge['id'], 'signature' => $signature])->assertUnprocessable();
    }
}
