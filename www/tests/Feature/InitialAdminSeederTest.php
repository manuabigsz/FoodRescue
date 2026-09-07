<?php

namespace Tests\Feature;

use App\Models\User;
use App\UserRole;
use App\UserStatus;
use Database\Seeders\InitialAdminSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InitialAdminSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_seed_creates_first_admin_from_configuration_and_is_idempotent(): void
    {
        config(['accounts.initial_admin' => [
            'name' => 'Initial Admin', 'email' => 'ADMIN@example.com', 'password' => 'a-long-admin-passphrase',
        ]]);

        $this->seed(InitialAdminSeeder::class);
        config(['accounts.initial_admin.password' => 'replacement-passphrase']);
        $this->seed(InitialAdminSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('roles', 5);
        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue(Hash::check('a-long-admin-passphrase', $user->password));
        $this->assertSame(UserStatus::Active, $user->status);
    }

    public function test_seed_refuses_missing_credentials_without_creating_default_user(): void
    {
        config(['accounts.initial_admin' => ['name' => null, 'email' => null, 'password' => null]]);

        try {
            $this->seed(InitialAdminSeeder::class);
            $this->fail('The seed should reject missing credentials.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('INITIAL_ADMIN_', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_seed_never_promotes_existing_public_account(): void
    {
        $user = User::factory()->withRole(UserRole::Buyer)->create(['email' => 'admin@example.com']);
        config(['accounts.initial_admin' => [
            'name' => 'Initial Admin', 'email' => 'admin@example.com', 'password' => 'a-long-admin-passphrase',
        ]]);

        try {
            $this->seed(InitialAdminSeeder::class);
            $this->fail('The seed should reject an existing public account.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Nenhuma promoção', $exception->getMessage());
        }

        $this->assertFalse($user->fresh()->hasRole('admin'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_seed_cannot_create_additional_admins_or_unblock_existing_admin(): void
    {
        $admin = User::factory()->withRole(UserRole::Admin)->blocked()->create();
        config(['accounts.initial_admin' => [
            'name' => 'New Admin', 'email' => 'new-admin@example.com', 'password' => 'a-long-admin-passphrase',
        ]]);

        $this->seed(InitialAdminSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame(UserStatus::Blocked, $admin->fresh()->status);
    }
}
