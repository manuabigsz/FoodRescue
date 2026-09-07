<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\Base58;
use App\UserRole;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProtocolConfigTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PROGRAM = 'Hqw1fUFkV2fQsASAht2c8HjK8XVFcQbhaXXjTAQ1Pxwr';

    private const MINT = '7yVZR9eQEqHoX73MDXgA7XrU3ukPGHWoHvaqcSR8KBpJ';

    private const CONFIG = 'ActKxMmsJW2tAqqL46LgTQ494Z3nfS4Y8ccPmhPXf1bQ';

    private const AUTHORITY = '2khEXeqcxVDREV9y63Bii9vFtPEpqpJwZHXfXMuEjciV';

    private const TREASURY = '2reRA5JzX4F6yaxrLgPoSs3VhLJuqWis1ph8Y1oJZEJj';

    private const SIGNATURE = '5f5r5AjuFd8WwUagQSztAgufUCE6rdYhXmjU5rtnBPsxmfC5fFCUGiqQCcQZmAfFzuo6gyYYm616Roc1HEhREX5';

    public function test_admin_prepares_and_confirms_official_protocol_config(): void
    {
        $this->seed(PermissionsSeeder::class);
        config([
            'services.solana.cluster' => 'devnet',
            'services.solana.rpc_url' => 'https://api.devnet.solana.com',
            'services.solana.program_id' => self::PROGRAM,
            'services.solana.token_mint' => self::MINT,
            'services.solana.protocol_authority' => self::AUTHORITY,
            'services.solana.protocol_treasury' => self::TREASURY,
        ]);
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/blockchain/protocol/prepare', ['config_pda' => self::CONFIG])
            ->assertOk()
            ->assertJsonPath('data.authority_wallet', self::AUTHORITY)
            ->assertJsonPath('data.treasury_wallet', self::TREASURY)
            ->assertJsonPath('data.initialize_instruction.accounts.0.signer', true);

        Http::fake(function (Request $request) {
            return match ($request->data()['method'] ?? null) {
                'getTransaction' => Http::response(['jsonrpc' => '2.0', 'result' => [
                    'slot' => 900, 'meta' => ['err' => null],
                    'transaction' => ['message' => [
                        'accountKeys' => [['pubkey' => self::CONFIG], ['pubkey' => self::AUTHORITY, 'signer' => true]],
                        'instructions' => [['programId' => self::PROGRAM, 'accounts' => [self::CONFIG], 'data' => Base58::encode(chr(2).Base58::decode(self::TREASURY))]],
                    ]],
                ], 'id' => 1]),
                'getSignatureStatuses' => Http::response(['jsonrpc' => '2.0', 'result' => [
                    'value' => [[
                        'slot' => 900, 'err' => null, 'confirmationStatus' => 'confirmed',
                    ]],
                ], 'id' => 1]),
                'getAccountInfo' => Http::response(['jsonrpc' => '2.0', 'result' => ['value' => [
                    'owner' => self::PROGRAM,
                    'data' => [base64_encode(
                        chr(1).chr(250)
                        .Base58::decode(self::AUTHORITY)
                        .Base58::decode(self::TREASURY)
                        .Base58::decode(self::MINT)
                    ), 'base64'],
                ]], 'id' => 1]),
                default => Http::response(['jsonrpc' => '2.0', 'error' => ['message' => 'unexpected']], 200),
            };
        });

        $this->postJson('/api/v1/admin/blockchain/protocol/confirm', [
            'signature' => self::SIGNATURE,
            'config_pda' => self::CONFIG,
        ])->assertCreated()
            ->assertJsonPath('data.config_pda', self::CONFIG)
            ->assertJsonPath('data.treasury_wallet', self::TREASURY);

        $this->assertDatabaseHas('blockchain_protocol_configs', [
            'program_id' => self::PROGRAM,
            'config_pda' => self::CONFIG,
            'treasury_wallet' => self::TREASURY,
        ]);
    }
}
