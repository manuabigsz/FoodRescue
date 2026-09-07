<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\Base58;
use App\UserRole;
use App\UserStatus;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['producer'])]
    #[TestWith(['buyer'])]
    #[TestWith(['carrier'])]
    #[TestWith(['ngo'])]
    public function test_public_registration_creates_active_account_without_approval(string $role): void
    {
        $this->seed(RolesSeeder::class);

        $response = $this->postJson('/api/v1/auth/register', $this->registration(['role' => $role]));

        $response->assertCreated()->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.roles', [$role])
            ->assertJsonPath('data.email', 'person@example.com')
            ->assertJsonPath('data.profile.phone', '+55 11 99999-0000')
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token')
            ->assertJsonMissingPath('data.token');
        $user = User::where('email', 'person@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('a-long-test-passphrase', $user->password));
        $this->assertSame(UserStatus::Active, $user->status);
    }

    #[TestWith(['role', 'admin'])]
    #[TestWith(['status', 'active'])]
    #[TestWith(['permissions', ['*']])]
    #[TestWith(['roles', ['admin']])]
    #[TestWith(['id', 1])]
    #[TestWith(['email_verified_at', '2026-09-01'])]
    public function test_public_registration_rejects_privilege_fields_with_422(string $key, mixed $value): void
    {
        $this->postJson('/api/v1/auth/register', $this->registration([$key => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($key === 'role' ? 'role' : 'request');
        $this->assertDatabaseCount('users', 0);
    }

    #[TestWith([['password' => 'short', 'password_confirmation' => 'short']])]
    #[TestWith([['password_confirmation' => 'does-not-match']])]
    #[TestWith([['email' => ['invalid']]])]
    #[TestWith([['email' => 'invalid']])]
    #[TestWith([['name' => ['invalid']]])]
    #[TestWith([['password' => 'éééééééééééééééééééééééééééééééééééééé', 'password_confirmation' => 'éééééééééééééééééééééééééééééééééééééé']])]
    public function test_invalid_registration_is_rejected_without_writes(array $changes): void
    {
        $this->postJson('/api/v1/auth/register', $this->registration($changes))->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_duplicate_email_is_case_insensitive(): void
    {
        User::factory()->create(['email' => 'person@example.com']);

        $this->postJson('/api/v1/auth/register', $this->registration())
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_issues_expiring_hashed_token_and_me_returns_only_allowed_fields(): void
    {
        Sleep::fake();
        $this->freezeTime();
        $user = User::factory()->withRole(UserRole::Buyer)->create();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => mb_strtoupper($user->email), 'password' => 'password', 'device_name' => 'tests',
        ]);

        $response->assertOk()->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.roles', ['buyer'])
            ->assertJsonMissingPath('data.user.password');
        $token = $response->json('data.token');
        $stored = PersonalAccessToken::findToken($token);
        $this->assertNotSame($token, $stored->token);
        $this->assertSame(now()->addHours(8)->timestamp, $stored->expires_at->timestamp);
        $this->getJson('/api/v1/auth/me', $this->bearer($token))
            ->assertOk()->assertExactJsonStructure(['data' => [
                'id', 'name', 'email', 'solana_wallet_address', 'solana_wallet_verified', 'solana_wallet_verified_at', 'status', 'roles', 'profile', 'created_at', 'updated_at',
            ]])->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_unknown_wrong_password_and_blocked_logins_have_same_401_response(): void
    {
        Sleep::fake();
        $active = User::factory()->create();
        $blocked = User::factory()->blocked()->create();

        foreach ([
            [$active->email, 'incorrect'],
            [$blocked->email, 'password'],
            ['missing@example.com', 'password'],
        ] as [$email, $password]) {
            $this->postJson('/api/v1/auth/login', compact('email', 'password'))
                ->assertUnauthorized()->assertExactJson(['message' => 'Credenciais inválidas.']);
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_protected_endpoints_require_authentication_even_without_accept_header(): void
    {
        $this->get('/api/v1/auth/me')->assertUnauthorized()->assertJsonStructure(['message']);
        $this->getJson('/api/v1/auth/me', $this->bearer('invalid'))->assertUnauthorized();
    }

    public function test_expired_and_blocked_tokens_are_rejected(): void
    {
        $user = User::factory()->create();
        $expired = $user->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        $this->getJson('/api/v1/auth/me', $this->bearer($expired))->assertUnauthorized();
        $user->status = UserStatus::Blocked;
        $user->save();
        $blocked = $user->createToken('blocked')->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/auth/me', $this->bearer($blocked))->assertForbidden();
    }

    public function test_logout_revokes_only_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('one')->plainTextToken;
        $other = $user->createToken('two')->plainTextToken;

        $this->post('/api/v1/auth/logout', [], $this->bearer($token))->assertNoContent();

        $this->assertNull(PersonalAccessToken::findToken($token));
        $this->assertNotNull(PersonalAccessToken::findToken($other));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $this->bearer($token))->assertUnauthorized();
    }

    public function test_logout_all_revokes_all_sessions(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('one')->plainTextToken;
        $user->createToken('two');

        $this->post('/api/v1/auth/logout-all', [], $this->bearer($token))->assertNoContent();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_login_caps_active_tokens(): void
    {
        Sleep::fake();
        $user = User::factory()->create();
        $old = $user->createToken('old')->plainTextToken;
        for ($i = 0; $i < 4; $i++) {
            $user->createToken('other');
        }

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        $this->assertSame(5, $user->tokens()->count());
        $this->assertNull(PersonalAccessToken::findToken($old));
    }

    public function test_password_change_requires_current_password_and_revokes_tokens(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('one')->plainTextToken;
        $user->createToken('two');

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'password', 'password' => 'another-long-passphrase',
            'password_confirmation' => 'another-long-passphrase',
        ], $this->bearer($token))->assertNoContent();

        $this->assertTrue(Hash::check('another-long-passphrase', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_wrong_current_password_returns_422_without_changes(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('one')->plainTextToken;

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'wrong', 'password' => 'another-long-passphrase',
            'password_confirmation' => 'another-long-passphrase',
        ], $this->bearer($token))->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_profile_updates_only_own_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = $user->createToken('one')->plainTextToken;

        $this->patchJson('/api/v1/auth/me', ['name' => 'Nome atualizado'], $this->bearer($token))
            ->assertOk()->assertJsonPath('data.name', 'Nome atualizado');

        $this->assertSame('Nome atualizado', $user->fresh()->name);
        $this->assertSame($other->name, $other->fresh()->name);
    }

    #[TestWith(['role', 'admin'])]
    #[TestWith(['status', 'active'])]
    #[TestWith(['user_id', 9])]
    #[TestWith(['password', 'new-password'])]
    public function test_profile_rejects_sensitive_fields_with_422(string $key, mixed $value): void
    {
        $user = User::factory()->withRole(UserRole::Buyer)->create();
        $token = $user->createToken('one')->plainTextToken;

        $this->patchJson('/api/v1/auth/me', [$key => $value], $this->bearer($token))
            ->assertUnprocessable()->assertJsonValidationErrors('request');

        $this->assertFalse($user->fresh()->hasRole('admin'));
    }

    public function test_email_change_requires_password_and_revokes_tokens(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('one')->plainTextToken;

        $this->patchJson('/api/v1/auth/me', ['email' => 'new@example.com'], $this->bearer($token))
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->patchJson('/api/v1/auth/me', [
            'email' => 'NEW@example.com', 'current_password' => 'password',
        ], $this->bearer($token))->assertOk()->assertJsonPath('data.email', 'new@example.com');

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_login_rate_limit_returns_429_with_retry_after(): void
    {
        Sleep::fake();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'missing@example.com', 'password' => 'incorrect',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'MISSING@example.com', 'password' => 'incorrect',
        ])->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_invalid_json_and_wrong_content_type_are_rejected(): void
    {
        $this->call('POST', '/api/v1/auth/login', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{broken')->assertBadRequest();

        $this->call('POST', '/api/v1/auth/login', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
        ], 'email=test')->assertStatus(415);
    }

    public function test_global_rate_limit_covers_unauthenticated_requests(): void
    {
        config(['accounts.requests_per_minute_per_ip' => 2]);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->getJson('/api/v1/auth/me')->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_registration_rate_limit_applies_to_invalid_payloads(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/register', ['email' => 'invalid'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/register', ['email' => 'invalid'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_oversized_json_is_rejected_before_validation(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => str_repeat('x', 17000)])
            ->assertStatus(413);
    }

    public function test_server_errors_never_expose_debug_details(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/v1/test-error', function (): void {
            throw new \RuntimeException('secret-database-password');
        });

        $this->getJson('/api/v1/test-error')
            ->assertStatus(500)->assertExactJson(['message' => 'Erro interno do servidor.']);
    }

    /** @return array<string, mixed> */
    private function registration(array $changes = []): array
    {
        $role = $changes['role'] ?? 'producer';

        $profiles = [
            'producer' => [
                'producer_type' => 'individual', 'document_number' => 'DOC-PRODUCER',
                'phone' => '+55 11 99999-0000', 'country' => 'Brazil', 'state' => 'SP',
                'city' => 'Campinas', 'address_line' => 'Rua Producer, 10', 'postal_code' => '13000-000',
            ],
            'buyer' => [
                'buyer_type' => 'individual', 'document_number' => 'DOC-BUYER',
                'phone' => '+55 11 99999-0000', 'country' => 'Brazil', 'state' => 'SP',
                'city' => 'Campinas', 'address_line' => 'Rua Buyer, 20', 'postal_code' => '13000-000',
            ],
            'carrier' => [
                'company_name' => 'Transportes Teste', 'document_number' => 'DOC-CARRIER',
                'phone' => '+55 11 99999-0000', 'contact_name' => 'Contato Carrier',
                'country' => 'Brazil', 'state' => 'SP', 'city' => 'Campinas',
                'address_line' => 'Rua Carrier, 30', 'postal_code' => '13000-000',
                'service_regions' => ['Campinas', 'São Paulo'], 'vehicle_types' => ['truck'],
                'max_capacity_kg' => 12000,
            ],
            'ngo' => [
                'organization_name' => 'Instituição Teste', 'registration_number' => 'REG-NGO',
                'phone' => '+55 11 99999-0000', 'contact_name' => 'Contato NGO',
                'country' => 'Brazil', 'state' => 'SP', 'city' => 'Campinas',
                'address_line' => 'Rua NGO, 40', 'postal_code' => '13000-000',
                'description' => 'Instituição social de teste.',
            ],
        ];

        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium extension is required.');
        }
        $keypair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keypair);
        $wallet = Base58::encode(sodium_crypto_sign_publickey($keypair));
        $challenge = $this->postJson('/api/v1/auth/wallet/challenge', ['wallet_address' => $wallet])
            ->assertCreated()->json('data');

        $base = [
            'name' => 'Pessoa Teste', 'email' => 'PERSON@example.com',
            'solana_wallet_address' => $wallet,
            'wallet_challenge_id' => $challenge['id'],
            'wallet_signature' => base64_encode(sodium_crypto_sign_detached($challenge['message'], $secret)),
            'password' => 'a-long-test-passphrase', 'password_confirmation' => 'a-long-test-passphrase',
            'role' => $role, 'profile' => $profiles[$role] ?? $profiles['producer'],
        ];

        return array_replace($base, $changes);
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
