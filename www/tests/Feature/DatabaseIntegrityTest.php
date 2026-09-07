<?php

namespace Tests\Feature;

use App\Enums\TradeStatus;
use App\Models\Rating;
use App\Models\SurplusLot;
use App\Models\User;
use App\Services\Logistics\TradeLogistics;
use App\Services\Marketplace\SurplusMarketplace;
use App\UserRole;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseIntegrityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_postgres_rejects_a_second_active_trade_even_when_application_locks_are_bypassed(): void
    {
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();
        $trade = app(SurplusMarketplace::class)->buyNow($buyer, SurplusLot::factory()->create());
        $duplicate = fn () => $trade->replicate()->save();
        $this->rejects('23505', $duplicate);
        $trade->update(['status' => TradeStatus::Expired]);
        $attempt = $trade->replicate();
        $attempt->status = TradeStatus::Reserved;
        $attempt->save();
        $this->assertDatabaseCount('trades', 2);
        $this->rejects('23505', fn () => $trade->update(['status' => TradeStatus::Completed]));
        $this->assertSame(TradeStatus::Expired, $trade->fresh()->status);
    }

    public function test_postgres_rejects_invalid_and_duplicate_ratings_without_http_validation(): void
    {
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();
        $trade = app(SurplusMarketplace::class)->buyNow($buyer, SurplusLot::factory()->create());
        $data = ['trade_id' => $trade->id, 'reviewer_id' => $buyer->id, 'target_user_id' => $trade->producer_id];
        foreach ([0, 6] as $rating) {
            $this->rejects('23514', fn () => Rating::create($data + ['rating' => $rating]));
        }
        $this->rejects('23514', fn () => Rating::create(array_replace($data, ['target_user_id' => $buyer->id, 'rating' => 5])));
        Rating::create($data + ['rating' => 5]);
        $this->rejects('23505', fn () => Rating::create($data + ['rating' => 4]));
        $this->assertDatabaseCount('ratings', 1);
    }

    public function test_selected_shipping_offer_foreign_key_rejects_dangling_references_and_clears_deleted_offer(): void
    {
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();
        $carrier = User::factory()->withRole(UserRole::Carrier)->create();
        $trade = app(SurplusMarketplace::class)->buyNow($buyer, SurplusLot::factory()->create());
        $request = app(TradeLogistics::class)->createShippingRequest($buyer, $trade, [
            'destination_address' => 'Warehouse', 'destination_city' => 'Campinas', 'destination_state' => 'SP', 'destination_country' => 'BR',
        ]);
        $this->rejects('23503', fn () => $request->update(['selected_shipping_offer_id' => 99999999]));
        $offer = app(TradeLogistics::class)->createOffer($carrier, $request, [
            'amount' => '10', 'pickup_at' => now()->addHour(), 'estimated_delivery_at' => now()->addHours(2),
        ]);
        app(TradeLogistics::class)->selectOffer($buyer, $trade, $offer);
        $this->assertSame($offer->id, $request->fresh()->selected_shipping_offer_id);
        $offer->delete();
        $this->assertNull($request->fresh()->selected_shipping_offer_id);
    }

    private function rejects(string $sqlState, callable $operation): void
    {
        try {
            // A savepoint recovers the surrounding PostgreSQL test transaction.
            DB::transaction($operation);
            $this->fail('PostgreSQL should reject the inconsistent write.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);
        }
    }
}
