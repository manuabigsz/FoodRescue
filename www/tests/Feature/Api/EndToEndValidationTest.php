<?php

namespace Tests\Feature\Api;

use App\Models\AgriculturalProduct;
use App\Models\BlockchainProtocolConfig;
use App\Models\Trade;
use App\Models\User;
use App\Support\Base58;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EndToEndValidationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private array $tokens = [];

    private array $prepared = [];

    private array $publicationKeys = [];

    private array $proof = [];

    /** Status do Proof of Rescue on-chain: 0 antes do produtor, 1 depois. */
    private int $proofStatus = 0;

    private int $chainStatus = 0;

    private int $tag = 0;

    private string $vaultAmount = '0';

    private int $signatureId = 20;

    public static function flows(): array
    {
        return [
            'commercial carrier' => [false, true, false],
            'mutual refund' => [false, true, true],
            'donation carrier' => [true, true, false],
            'donation NGO managed' => [true, false, false],
        ];
    }

    #[DataProvider('flows')]
    public function test_registered_actors_complete_the_entire_api_lifecycle(bool $donation, bool $carrierManaged, bool $cancel): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(PermissionsSeeder::class);
        $producer = $this->register('producer');
        $recipient = $this->register($donation ? 'ngo' : 'buyer');
        $carrier = $this->register('carrier');
        $program = $this->address(80);
        config(['services.solana.program_id' => $program, 'services.solana.cluster' => 'devnet']);
        BlockchainProtocolConfig::create([
            'cluster' => 'devnet', 'program_id' => $program, 'mint' => $this->address(81),
            'authority_wallet' => $this->address(82), 'treasury_wallet' => $this->address(83),
            'config_pda' => $this->address(84), 'version' => 1, 'confirmed_at' => now(),
        ]);
        $this->fakeChain();
        $product = AgriculturalProduct::factory()->create();
        $lotPayload = [
            'agricultural_product_id' => $product->id, 'quantity' => '20.125', 'unit' => 'box',
            'origin_address' => 'Farm', 'origin_city' => 'Campinas', 'origin_state' => 'SP', 'origin_country' => 'BR',
            'harvest_date' => now()->toDateString(), 'available_until' => now()->addDay()->toISOString(),
            'asking_price' => '100.123456', 'minimum_price' => '90', 'donation_eligible' => $donation,
            'accepted_logistics_modes' => ['buyer_pickup', 'third_party_carrier'],
        ];
        $challenge = $this->asActor($producer)->postEmptyJson('/api/v1/auth/wallet/surplus-publication-challenge')
            ->assertCreated()->json('data');
        $lotId = $this->asActor($producer)->postJson('/api/v1/surplus', $lotPayload + [
            'wallet_challenge_id' => $challenge['id'],
            'wallet_signature' => base64_encode(sodium_crypto_sign_detached(
                $challenge['message'],
                sodium_crypto_sign_secretkey($this->publicationKeys[$producer->id]),
            )),
        ])->assertCreated()->json('data.id');
        $this->asActor($recipient)->getJson('/api/v1/surplus/'.$lotId)->assertOk()->assertJsonMissingPath('data.minimum_price');
        $id = $this->postEmptyJson('/api/v1/surplus/'.$lotId.($donation ? '/donations/accept' : '/buy-now'))
            ->assertCreated()->json('data.id');
        $path = '/api/v1/trades/'.$id;
        $destination = ['destination_address' => 'Warehouse', 'destination_city' => 'Campinas', 'destination_state' => 'SP', 'destination_country' => 'BR'];
        if ($carrierManaged) {
            $shippingId = $this->postJson($path.'/shipping', $destination)->assertCreated()->json('data.id');
            $offerId = $this->asActor($carrier)->postJson('/api/v1/shipping-requests/'.$shippingId.'/offers', [
                'amount' => '12.345678', 'pickup_at' => now()->addHour()->toISOString(),
                'estimated_delivery_at' => now()->addHours(2)->toISOString(),
            ])->assertCreated()->json('data.id');
            $this->asActor($recipient)->postEmptyJson($path.'/shipping-offers/'.$offerId.'/select')->assertOk()->assertJsonPath('data.status', 'waiting_payment');
            $this->prepared = $this->postEmptyJson($path.'/blockchain/prepare')->assertOk()->json('data');
            $this->assertSame($donation ? '0' : '2002469', $this->prepared['amounts']['protocol_fee']);
            $this->postJson($path.'/blockchain/initialize/confirm', [
                'signature' => $this->signature(), 'trade_pda' => $this->address(85), 'vault_token_account' => $this->address(86),
            ])->assertCreated();
            $this->chainStatus = 1;
            $this->tag = 1;
            $this->vaultAmount = $this->prepared['amounts']['total'];
            $this->postJson($path.'/blockchain/funding/confirm', ['signature' => $this->signature()])->assertOk()->assertJsonPath('data.status', 'funded');
        } else {
            $this->postJson($path.'/ngo-managed', $destination)->assertOk()->assertJsonPath('data.status', 'funded');
        }
        if ($cancel) {
            $this->postEmptyJson($path.'/blockchain/cancellation/prepare')->assertOk()->assertJsonCount(2, 'data.required_signers');
            $this->chainStatus = 3;
            $this->tag = 4;
            $this->vaultAmount = '0';
            $this->postJson($path.'/blockchain/cancellation/confirm', ['signature' => $this->signature(), 'reason' => 'Mutual cancellation'])
                ->assertOk()->assertJsonPath('data.status', 'cancelled');
            $trade = Trade::findOrFail($id);
            $this->assertNotNull($trade->blockchainAccount->refunded_at);
            $this->assertDatabaseHas('surplus_lots', ['id' => $lotId, 'status' => 'open']);
            $this->getJson('/api/v1/dashboard/buyer')->assertOk()->assertJsonPath('data.summary.total_spend', '0.000000');

            return;
        }
        $transport = $carrierManaged ? $carrier : $recipient;
        if ($carrierManaged) {
            $this->asActor($recipient)->postEmptyJson($path.'/delivery/delivered/prepare')->assertConflict();
            $this->asActor($producer)->postEmptyJson($path.'/delivery/ready-for-pickup/prepare')->assertOk();
            $this->chainStatus = 4;
            $this->tag = 6;
            $this->asActor($producer)->postJson($path.'/delivery/ready-for-pickup', ['signature' => $this->signature()])->assertOk();
            $this->asActor($transport)->postEmptyJson($path.'/delivery/pickup/prepare')->assertOk();
            $this->chainStatus = 5;
            $this->tag = 7;
            $this->asActor($transport)->postJson($path.'/delivery/pickup', ['signature' => $this->signature()])->assertOk();
            $this->asActor($transport)->postEmptyJson($path.'/delivery/delivered/prepare')->assertOk();
            $this->chainStatus = 6;
            $this->tag = 8;
            $this->asActor($transport)->postJson($path.'/delivery/delivered', ['signature' => $this->signature()])->assertOk()->assertJsonPath('data.status', 'delivered');
        } else {
            $this->asActor($recipient)->postEmptyJson($path.'/delivery/delivered')->assertConflict();
            $this->asActor($producer)->postEmptyJson($path.'/delivery/ready-for-pickup')->assertOk();
            $this->asActor($transport)->postEmptyJson($path.'/delivery/pickup')->assertOk();
            $this->postEmptyJson($path.'/delivery/delivered')->assertOk()->assertJsonPath('data.status', 'delivered');
        }
        $this->asActor($recipient);
        if ($carrierManaged) {
            $this->postEmptyJson($path.'/blockchain/settlement/prepare')->assertOk();
            $this->chainStatus = 2;
            $this->tag = 3;
            $this->vaultAmount = $this->prepared['amounts']['total'];
            $this->postJson($path.'/blockchain/settlement/confirm', ['signature' => $this->signature()])
                ->assertOk()->assertJsonPath('data.status', $donation ? 'proof_pending' : 'completed');
        }
        if ($donation) {
            // A atestação é feita em duas transações: a NGO abre, o produtor fecha.
            $this->proof = $this->postEmptyJson($path.'/rescue-proof/prepare')->assertOk()->json('data');
            $this->tag = 5;
            $this->proofStatus = 0;
            $this->postJson($path.'/rescue-proof/confirm', ['signature' => $this->signature(), 'proof_pda' => $this->address(87)])
                ->assertCreated()->assertJsonPath('data.awaiting_producer', true);
            $this->assertDatabaseHas('trades', ['id' => $id, 'status' => 'proof_pending']);

            $this->asActor($producer);
            $this->postEmptyJson($path.'/rescue-proof/producer/prepare')->assertOk();
            $this->tag = 9;
            $this->proofStatus = 1;
            $this->postJson($path.'/rescue-proof/producer/confirm', ['signature' => $this->signature()])
                ->assertOk()->assertJsonPath('data.awaiting_producer', false)
                ->assertJsonPath('data.metadata_hash', $this->proof['metadata_hash']);
            $this->asActor($recipient);
        }
        $this->assertDatabaseHas('trades', ['id' => $id, 'status' => 'completed']);
        $this->assertDatabaseHas('surplus_lots', ['id' => $lotId, 'status' => $donation ? 'donated' : 'sold']);
        $this->postJson($path.'/ratings', ['target_user_id' => $producer->id, 'rating' => 5])->assertCreated();
        $this->getJson('/api/v1/users/'.$producer->id.'/reputation')->assertOk()->assertJsonPath('data.count', 1);
        $this->getJson('/api/v1/dashboard/'.($donation ? 'ngo' : 'buyer'))->assertOk();
        $this->asActor($producer)->getJson('/api/v1/dashboard/producer')->assertOk()
            ->assertJsonPath('data.summary.recovered_revenue', $donation ? '0.000000' : '98.120987');
        if ($carrierManaged) {
            $this->asActor($carrier)->getJson('/api/v1/dashboard/carrier')->assertOk()->assertJsonPath('data.summary.freight_revenue', '12.345678');
        }
    }

    private function register(string $role): User
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => '']);
        $key = sodium_crypto_sign_keypair();
        $wallet = Base58::encode(sodium_crypto_sign_publickey($key));
        $challenge = $this->postJson('/api/v1/auth/wallet/challenge', ['wallet_address' => $wallet])->assertCreated()->json('data');
        $profile = ['phone' => '12345', 'country' => 'Brazil', 'state' => 'SP', 'city' => 'Campinas', 'address_line' => 'Main road'];
        $profile += match ($role) {
            'producer' => ['producer_type' => 'individual', 'document_number' => 'P1'],
            'buyer' => ['buyer_type' => 'individual', 'document_number' => 'B1'],
            'carrier' => ['company_name' => 'Transport', 'document_number' => 'C1', 'contact_name' => 'Carrier', 'service_regions' => ['SP']],
            'ngo' => ['organization_name' => 'Rescue', 'registration_number' => 'N1', 'contact_name' => 'NGO'],
        };
        $email = $role.'@example.com';
        $password = 'Validation password 123!';
        $id = $this->postJson('/api/v1/auth/register', [
            'name' => $role, 'email' => $email, 'password' => $password, 'password_confirmation' => $password,
            'role' => $role, 'profile' => $profile, 'solana_wallet_address' => $wallet,
            'wallet_challenge_id' => $challenge['id'],
            'wallet_signature' => base64_encode(sodium_crypto_sign_detached($challenge['message'], sodium_crypto_sign_secretkey($key))),
        ])->assertCreated()->assertJsonPath('data.solana_wallet_verified', true)->json('data.id');
        $this->publicationKeys[$id] = $key;
        $this->tokens[$id] = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->assertOk()->json('data.token');

        return User::findOrFail($id);
    }

    private function asActor(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokens[$user->id]);
    }

    private function address(int $byte): string
    {
        return Base58::encode(str_repeat(chr($byte), 32));
    }

    private function signature(): string
    {
        return Base58::encode(str_repeat(chr($this->signatureId++), 64));
    }

    private function fakeChain(): void
    {
        Http::fake(function (Request $request) {
            $method = $request->data()['method'];
            $p = $this->prepared;
            $proof = $this->proof;
            $pda = $this->address(in_array($this->tag, [5, 9], true) ? 87 : 85);
            // As duas instruções do Proof of Rescue tiram os signatários da
            // preparação do proof; as demais, da preparação do trade.
            $wallets = in_array($this->tag, [5, 9], true) ? $proof['wallets'] : ($p['wallets'] ?? []);
            $keys = array_map(fn ($wallet) => ['pubkey' => $wallet, 'signer' => true], array_values(array_filter($wallets)));
            $vaultIndex = count($keys);
            $keys[] = ['pubkey' => $this->address(86), 'signer' => false];
            $instruction = match ($this->tag) {
                0 => isset($p['initialize_instruction']) ? base64_decode($p['initialize_instruction']['data_base64']) : '',
                5 => base64_decode($proof['instruction']['data_base64']),
                default => chr($this->tag),
            };
            $result = match ($method) {
                'getTokenSupply' => ['value' => ['decimals' => 6]],
                'getSignatureStatuses' => ['value' => [['slot' => 100, 'err' => null, 'confirmationStatus' => 'confirmed']]],
                'getTransaction' => [
                    'slot' => 100, 'meta' => ['err' => null, 'preTokenBalances' => [[
                        'accountIndex' => $vaultIndex, 'mint' => $this->address(81), 'owner' => $this->address(85),
                        'uiTokenAmount' => ['amount' => $p['amounts']['total'] ?? '0'],
                    ]], 'innerInstructions' => [['index' => 0, 'instructions' => [[
                        'programId' => 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA',
                        'parsed' => ['type' => 'transferChecked', 'info' => [
                            'source' => $this->address(86), 'mint' => $this->address(81), 'authority' => $this->address(85),
                            'tokenAmount' => ['amount' => $p['amounts']['total'] ?? '0'],
                        ]],
                    ]]]]], 'transaction' => ['message' => [
                        'accountKeys' => $keys,
                        'instructions' => [['programId' => $this->address(80), 'accounts' => [$pda], 'data' => Base58::encode($instruction)]],
                    ]],
                ],
                'getAccountInfo' => ['value' => $this->chainAccount($request->data()['params'][0])],
                default => throw new \RuntimeException('Unexpected RPC method '.$method),
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        });
        Http::preventStrayRequests();
    }

    private function chainAccount(string $address): array
    {
        if ($address === $this->address(86)) {
            if (in_array($this->chainStatus, [2, 3], true) && $this->vaultAmount !== '0') {
                $this->vaultAmount = '0';
            }
            $vaultAmount = $this->vaultAmount;

            return ['owner' => 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA', 'data' => ['parsed' => ['info' => [
                'owner' => $this->address(85), 'mint' => $this->address(81), 'tokenAmount' => ['amount' => $vaultAmount],
            ]]]];
        }
        if ($address === $this->address(87)) {
            $p = $this->proof;
            $raw = chr(2).chr(250).pack('P', $p['trade_id']).Base58::decode($p['wallets']['producer'])
                .Base58::decode($p['wallets']['ngo']).($p['wallets']['carrier'] ? Base58::decode($p['wallets']['carrier']) : str_repeat("\0", 32))
                .hex2bin($p['metadata_hash']).pack('P', now()->timestamp).chr($this->proofStatus);
        } else {
            $p = $this->prepared;
            $raw = chr(2).chr(254).chr(253).pack('P', $p['trade_id']).Base58::decode($p['wallets']['buyer'])
                .Base58::decode($p['wallets']['producer']).($p['wallets']['carrier'] ? Base58::decode($p['wallets']['carrier']) : str_repeat("\0", 32))
                .Base58::decode($p['mint']).Base58::decode($this->address(86)).Base58::decode($p['protocol_config']['pda']);
            foreach (['product', 'shipping', 'protocol_fee', 'total'] as $amount) {
                $raw .= pack('P', (int) $p['amounts'][$amount]);
            }
            $raw .= pack('P', strtotime($p['payment_expires_at'])).chr($this->chainStatus);
        }

        return ['owner' => $this->address(80), 'data' => [base64_encode($raw), 'base64']];
    }
}
