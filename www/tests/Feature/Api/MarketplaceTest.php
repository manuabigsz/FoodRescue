<?php

namespace Tests\Feature\Api;

use App\Enums\OfferStatus;
use App\Enums\SurplusStatus;
use App\Models\AgriculturalProduct;
use App\Models\QualityGrade;
use App\Models\SurplusLot;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MarketplaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private array $publicationKeys = [];

    public function test_producer_creates_surplus_and_private_minimum_price_is_hidden_from_buyer(): void
    {
        $producer = User::factory()->withRole(UserRole::Producer)->create();
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();
        $product = AgriculturalProduct::factory()->create();
        $quality = QualityGrade::factory()->create();

        $this->prepareProducerWallet($producer);
        $response = $this->postJson('/api/v1/surplus', $this->signedLotPayload($producer, $this->lotPayload($product->id, $quality->id)))
            ->assertCreated()
            ->assertJsonPath('data.minimum_price', '18000.000000');

        $lotId = $response->json('data.id');
        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/surplus/'.$lotId)
            ->assertOk()
            ->assertJsonMissingPath('data.minimum_price');
    }

    public function test_buyer_offer_can_be_accepted_and_atomically_reserves_entire_lot(): void
    {
        $lot = SurplusLot::factory()->create();
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();

        Sanctum::actingAs($buyer);
        $offerResponse = $this->postJson('/api/v1/surplus/'.$lot->id.'/offers', ['amount' => '19000.000000'])
            ->assertCreated();
        $offerId = $offerResponse->json('data.id');

        Sanctum::actingAs($lot->producer);
        $this->postEmptyJson('/api/v1/offers/'.$offerId.'/accept')
            ->assertCreated()
            ->assertJsonPath('data.product_amount', '19000.000000')
            ->assertJsonPath('data.protocol_fee', '380.000000')
            ->assertJsonPath('data.status', 'reserved');

        $this->assertSame(SurplusStatus::Reserved, $lot->fresh()->status);
        $this->assertDatabaseHas('offers', ['id' => $offerId, 'status' => OfferStatus::Accepted->value]);
        $this->assertDatabaseHas('trades', ['surplus_lot_id' => $lot->id, 'buyer_id' => $buyer->id]);
    }

    public function test_producer_delivery_is_not_an_available_logistics_mode(): void
    {
        $producer = User::factory()->withRole(UserRole::Producer)->create();
        $product = AgriculturalProduct::factory()->create();
        $quality = QualityGrade::factory()->create();

        $this->prepareProducerWallet($producer);
        $this->postJson('/api/v1/surplus', array_replace(
            $this->signedLotPayload($producer, $this->lotPayload($product->id, $quality->id)),
            ['accepted_logistics_modes' => ['producer_delivery']],
        ))->assertUnprocessable();
    }

    public function test_buy_now_rejects_pending_offers_and_prevents_second_reservation(): void
    {
        $lot = SurplusLot::factory()->create();
        $firstBuyer = User::factory()->withRole(UserRole::Buyer)->create();
        $secondBuyer = User::factory()->withRole(UserRole::Buyer)->create();

        Sanctum::actingAs($firstBuyer);
        $this->postJson('/api/v1/surplus/'.$lot->id.'/offers', ['amount' => '17000'])->assertCreated();

        Sanctum::actingAs($secondBuyer);
        $this->postEmptyJson('/api/v1/surplus/'.$lot->id.'/buy-now')
            ->assertCreated()
            ->assertJsonPath('data.product_amount', '20000.000000');

        Sanctum::actingAs($firstBuyer);
        $this->postEmptyJson('/api/v1/surplus/'.$lot->id.'/buy-now')->assertConflict();
        $this->assertDatabaseCount('trades', 1);
        $this->assertDatabaseMissing('offers', ['surplus_lot_id' => $lot->id, 'status' => OfferStatus::Pending->value]);
    }

    public function test_catalog_search_matches_product_name_and_origin_city(): void
    {
        $tomato = SurplusLot::factory()->create([
            'agricultural_product_id' => AgriculturalProduct::factory()->create(['name' => 'Tomate italiano']),
            'origin_city' => 'Mogi das Cruzes',
        ]);
        $carrot = SurplusLot::factory()->create([
            'agricultural_product_id' => AgriculturalProduct::factory()->create(['name' => 'Cenoura']),
            'origin_city' => 'São Gotardo',
        ]);

        Sanctum::actingAs(User::factory()->withRole(UserRole::Buyer)->create());

        $this->getJson('/api/v1/surplus?search=tomate')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tomato->id);

        $this->getJson('/api/v1/surplus?search=gotardo')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $carrot->id);

        $this->getJson('/api/v1/surplus?search=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_mine_filter_returns_the_full_history_of_the_producer_own_lots(): void
    {
        $producer = User::factory()->withRole(UserRole::Producer)->create();
        $open = SurplusLot::factory()->create(['producer_id' => $producer->id]);
        $reserved = SurplusLot::factory()->create(['producer_id' => $producer->id, 'status' => SurplusStatus::Reserved]);
        $expired = SurplusLot::factory()->create(['producer_id' => $producer->id, 'available_until' => now()->subDay()]);
        $foreign = SurplusLot::factory()->create();

        Sanctum::actingAs($producer);
        $mine = $this->getJson('/api/v1/surplus?mine=1')->assertOk()->json('data');
        $ids = array_column($mine, 'id');

        sort($ids);
        $expected = [$open->id, $reserved->id, $expired->id];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertNotContains($foreign->id, $ids);

        $this->getJson('/api/v1/surplus')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_producer_lists_offers_of_own_lot_and_buyer_contact_stays_private(): void
    {
        $lot = SurplusLot::factory()->create();
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();

        Sanctum::actingAs($buyer);
        $offerId = $this->postJson('/api/v1/surplus/'.$lot->id.'/offers', ['amount' => '19000'])
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($lot->producer);
        $this->getJson('/api/v1/surplus/'.$lot->id.'/offers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $offerId)
            ->assertJsonPath('data.0.buyer.id', $buyer->id)
            ->assertJsonMissingPath('data.0.buyer.email');

        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/surplus/'.$lot->id.'/offers')->assertForbidden();
    }

    public function test_producer_contact_is_not_exposed_in_the_public_catalog(): void
    {
        $lot = SurplusLot::factory()->create();

        Sanctum::actingAs(User::factory()->withRole(UserRole::Buyer)->create());
        $this->getJson('/api/v1/surplus/'.$lot->id)
            ->assertOk()
            ->assertJsonPath('data.producer.id', $lot->producer_id)
            ->assertJsonMissingPath('data.producer.email');
    }

    /** @return array<string, mixed> */
    private function lotPayload(int $productId, int $qualityId): array
    {
        return [
            'agricultural_product_id' => $productId,
            'quality_grade_id' => $qualityId,
            'quantity' => '8000',
            'unit' => 'kg',
            'origin_address' => 'Zona Rural, km 12',
            'origin_city' => 'Campinas',
            'origin_state' => 'SP',
            'origin_country' => 'BR',
            'harvest_date' => now()->toDateString(),
            'available_until' => now()->addDay()->toISOString(),
            'asking_price' => '20000',
            'minimum_price' => '18000',
            'donation_eligible' => true,
            'accepted_logistics_modes' => ['buyer_pickup', 'third_party_carrier'],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function signedLotPayload(User $producer, array $payload): array
    {
        $challenge = $this->postEmptyJson('/api/v1/auth/wallet/surplus-publication-challenge')
            ->assertCreated()->json('data');

        return $payload + [
            'wallet_challenge_id' => $challenge['id'],
            'wallet_signature' => base64_encode(sodium_crypto_sign_detached(
                $challenge['message'],
                sodium_crypto_sign_secretkey($this->publicationKeys[$producer->id]),
            )),
        ];
    }

    private function prepareProducerWallet(User $producer): void
    {
        $key = sodium_crypto_sign_keypair();
        $producer->update([
            'solana_wallet_address' => \App\Support\Base58::encode(sodium_crypto_sign_publickey($key)),
            'solana_wallet_verified_at' => now(),
        ]);
        $this->publicationKeys[$producer->id] = $key;
        Sanctum::actingAs($producer);
    }
}
