<?php

namespace Tests\Feature\Api;

use App\Enums\TradeStatus;
use App\Models\Rating;
use App\Models\SurplusLot;
use App\Models\Trade;
use App\Models\User;
use App\UserRole;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RatingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionsSeeder::class);
    }

    public function test_buyer_can_rate_producer_after_completed_trade(): void
    {
        [$producer, $buyer, $trade] = $this->completedTrade();

        $response = $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/trades/{$trade->id}/ratings", [
            'target_user_id' => $producer->id,
            'rating' => 5,
            'comment' => 'Tudo certo.',
        ]);

        $response->assertSuccessful()->assertJsonPath('data.rating', 5);
        $this->assertDatabaseHas('ratings', [
            'trade_id' => $trade->id,
            'reviewer_id' => $buyer->id,
            'target_user_id' => $producer->id,
            'rating' => 5,
        ]);
    }

    public function test_rating_is_rejected_before_trade_completion(): void
    {
        [$producer, $buyer, $trade] = $this->completedTrade();
        $trade->update(['status' => TradeStatus::Delivered]);

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/trades/{$trade->id}/ratings", [
            'target_user_id' => $producer->id,
            'rating' => 4,
        ])->assertUnprocessable();
    }

    public function test_duplicate_rating_for_same_target_is_rejected(): void
    {
        [$producer, $buyer, $trade] = $this->completedTrade();
        Rating::query()->create([
            'trade_id' => $trade->id,
            'reviewer_id' => $buyer->id,
            'target_user_id' => $producer->id,
            'rating' => 5,
        ]);

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/trades/{$trade->id}/ratings", [
            'target_user_id' => $producer->id,
            'rating' => 4,
        ])->assertUnprocessable();
    }

    public function test_reputation_returns_average_and_count(): void
    {
        [$producer, $buyer, $trade] = $this->completedTrade();
        $buyer2 = User::factory()->withRole(UserRole::Buyer)->create();
        $trade2 = $this->makeTrade($producer, $buyer2);

        Rating::query()->create(['trade_id' => $trade->id, 'reviewer_id' => $buyer->id, 'target_user_id' => $producer->id, 'rating' => 5]);
        Rating::query()->create(['trade_id' => $trade2->id, 'reviewer_id' => $buyer2->id, 'target_user_id' => $producer->id, 'rating' => 3]);

        $this->actingAs($buyer, 'sanctum')->getJson("/api/v1/users/{$producer->id}/reputation")
            ->assertOk()
            ->assertJsonPath('data.average', 4)
            ->assertJsonPath('data.count', 2);
    }

    /** @return array{User, User, Trade} */
    private function completedTrade(): array
    {
        $producer = User::factory()->withRole(UserRole::Producer)->create();
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();

        return [$producer, $buyer, $this->makeTrade($producer, $buyer)];
    }

    private function makeTrade(User $producer, User $buyer): Trade
    {
        $lot = SurplusLot::factory()->create(['producer_id' => $producer->id]);

        return Trade::query()->create([
            'surplus_lot_id' => $lot->id,
            'producer_id' => $producer->id,
            'buyer_id' => $buyer->id,
            'product_amount' => '100.000000',
            'shipping_amount' => '0.000000',
            'protocol_fee' => '2.000000',
            'status' => TradeStatus::Completed,
            'is_donation' => false,
            'completed_at' => now(),
        ]);
    }
}
