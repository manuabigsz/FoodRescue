<?php

namespace Tests\Feature\Api;

use App\Enums\TradeStatus;
use App\Models\BlockchainTradeAccount;
use App\Models\SurplusLot;
use App\Models\Trade;
use App\Models\User;
use App\Services\Logistics\TradeLogistics;
use App\Services\Marketplace\SurplusMarketplace;
use App\Support\Base58;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeliveryLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_buyer_managed_delivery_advances_only_through_expected_states(): void
    {
        $producer = User::factory()->withRole(UserRole::Producer)->create();
        $lot = SurplusLot::factory()->create(['producer_id' => $producer->id]);
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();
        $trade = app(SurplusMarketplace::class)->buyNow($buyer, $lot);
        app(TradeLogistics::class)->buyerManaged($buyer, $trade, [
            'destination_address' => 'Rua Destino, 10',
            'destination_city' => 'Campinas',
            'destination_state' => 'SP',
            'destination_country' => 'BR',
        ]);
        $trade->refresh()->update(['status' => TradeStatus::Funded, 'payment_expires_at' => null]);

        $this->prepareChain($trade, $producer, $buyer);
        Sanctum::actingAs($producer);
        $this->postEmptyJson('/api/v1/trades/'.$trade->id.'/delivery/ready-for-pickup/prepare')->assertOk();
        $this->postJson('/api/v1/trades/'.$trade->id.'/delivery/ready-for-pickup', ['signature' => Base58::encode(str_repeat(chr(31), 64))])
            ->assertOk()
            ->assertJsonPath('data.status', TradeStatus::ReadyForPickup->value);

        Sanctum::actingAs($buyer);
        $this->postEmptyJson('/api/v1/trades/'.$trade->id.'/delivery/pickup/prepare')->assertOk();
        $this->postJson('/api/v1/trades/'.$trade->id.'/delivery/pickup', ['signature' => Base58::encode(str_repeat(chr(32), 64))])
            ->assertOk()
            ->assertJsonPath('data.status', TradeStatus::InTransit->value);

        $this->postEmptyJson('/api/v1/trades/'.$trade->id.'/delivery/delivered/prepare')->assertOk();
        $this->postJson('/api/v1/trades/'.$trade->id.'/delivery/delivered', ['signature' => Base58::encode(str_repeat(chr(33), 64))])
            ->assertOk()
            ->assertJsonPath('data.status', TradeStatus::Delivered->value);

        $trade->refresh();
        $this->assertNotNull($trade->ready_for_pickup_at);
        $this->assertNotNull($trade->picked_up_at);
        $this->assertNotNull($trade->delivered_at);
    }

    public function test_buyer_cannot_confirm_pickup_when_a_carrier_was_selected(): void
    {
        $producer = User::factory()->withRole(UserRole::Producer)->create();
        $lot = SurplusLot::factory()->create(['producer_id' => $producer->id]);
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();
        $carrier = User::factory()->withRole(UserRole::Carrier)->create();
        $trade = app(SurplusMarketplace::class)->buyNow($buyer, $lot);

        $shipping = app(TradeLogistics::class)->createShippingRequest($buyer, $trade, [
            'destination_address' => 'Rua Destino, 10',
            'destination_city' => 'Campinas',
            'destination_state' => 'SP',
            'destination_country' => 'BR',
        ]);
        $offer = app(TradeLogistics::class)->createOffer($carrier, $shipping, [
            'amount' => '480.000000',
            'pickup_at' => now()->addHour()->toISOString(),
            'estimated_delivery_at' => now()->addHours(5)->toISOString(),
        ]);
        app(TradeLogistics::class)->selectOffer($buyer, $trade, $offer);
        $trade->refresh()->update(['status' => TradeStatus::Funded, 'payment_expires_at' => null]);

        $this->prepareChain($trade, $producer, $buyer, $carrier);
        Sanctum::actingAs($producer);
        $this->postEmptyJson('/api/v1/trades/'.$trade->id.'/delivery/ready-for-pickup/prepare')->assertOk();
        $this->postJson('/api/v1/trades/'.$trade->id.'/delivery/ready-for-pickup', ['signature' => Base58::encode(str_repeat(chr(41), 64))])->assertOk();

        Sanctum::actingAs($buyer);
        $this->postEmptyJson('/api/v1/trades/'.$trade->id.'/delivery/pickup/prepare')->assertForbidden();

        Sanctum::actingAs($carrier);
        $this->postEmptyJson('/api/v1/trades/'.$trade->id.'/delivery/pickup/prepare')->assertOk();
        $this->postJson('/api/v1/trades/'.$trade->id.'/delivery/pickup', ['signature' => Base58::encode(str_repeat(chr(42), 64))])
            ->assertOk()
            ->assertJsonPath('data.status', TradeStatus::InTransit->value);
    }

    private function prepareChain(Trade $trade, User $producer, User $buyer, ?User $carrier = null): void
    {
        foreach ([$producer, $buyer, $carrier] as $actor) {
            $actor?->update([
                'solana_wallet_address' => Base58::encode(str_repeat(chr(10 + $actor->id), 32)),
                'solana_wallet_verified_at' => now(),
            ]);
        }
        $producer->refresh();
        $buyer->refresh();
        $carrier?->refresh();
        $program = Base58::encode(str_repeat(chr(80), 32));
        $mint = Base58::encode(str_repeat(chr(81), 32));
        $tradePda = Base58::encode(str_repeat(chr(85), 32));
        $vault = Base58::encode(str_repeat(chr(86), 32));
        $trade->update(['blockchain_preparation' => [
            'program_id' => $program,
            'trade_id' => $trade->id,
            'mint' => $mint,
            'payment_expires_at' => null,
            'amounts' => ['product' => '100000000', 'shipping' => '0', 'protocol_fee' => '2000000', 'total' => '100000000'],
            'wallets' => ['buyer' => $buyer->solana_wallet_address, 'producer' => $producer->solana_wallet_address, 'carrier' => null],
            'protocol_config' => ['pda' => Base58::encode(str_repeat(chr(84), 32))],
        ]]);
        BlockchainTradeAccount::create([
            'trade_id' => $trade->id, 'cluster' => 'devnet', 'program_id' => $program, 'mint' => $mint,
            'token_decimals' => 6, 'trade_pda' => $tradePda, 'vault_token_account' => $vault, 'initialized_at' => now(), 'funded_at' => now(),
        ]);
        Http::fake(function (Request $request) use ($producer, $buyer, $carrier) {
            $params = $request->data()['params'] ?? [];
            $signature = $request->data()['method'] === 'getSignatureStatuses'
                ? ($params[0][0] ?? '')
                : ($params[0] ?? '');
            $signatureByte = ord(Base58::decode($signature)[0] ?? chr(31));
            $tag = match ($signatureByte) {
                32, 42 => 7,
                33, 43 => 8,
                default => 6,
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => match ($request->data()['method']) {
                'getSignatureStatuses' => ['value' => [['slot' => 1, 'err' => null, 'confirmationStatus' => 'confirmed']]],
                'getTransaction' => ['slot' => 1, 'meta' => ['err' => null], 'transaction' => ['message' => [
                    'accountKeys' => array_map(fn (User $actor) => ['pubkey' => $actor->solana_wallet_address, 'signer' => true], array_values(array_filter([$producer, $buyer, $carrier]))),
                    'instructions' => [['programId' => Base58::encode(str_repeat(chr(80), 32)), 'accounts' => [Base58::encode(str_repeat(chr(85), 32))], 'data' => Base58::encode(chr($tag))]],
                ]]],
                default => throw new \RuntimeException('Unexpected RPC method '.$request->data()['method']),
            }]);
        });
    }
}
