<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\UserRole;
use App\UserStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['producer'])]
    #[TestWith(['buyer'])]
    #[TestWith(['carrier'])]
    #[TestWith(['ngo'])]
    public function test_non_admin_policy_denies_every_administrative_ability(string $role): void
    {
        $user = User::factory()->withRole(UserRole::from($role))->create();
        $target = User::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows('viewAny', User::class));
        $this->assertFalse(Gate::forUser($user)->allows('createAdmin', User::class));
        $this->assertFalse(Gate::forUser($user)->allows('view', $target));
        $this->assertFalse(Gate::forUser($user)->allows('updateStatus', $target));
    }

    public function test_non_admin_cannot_read_create_or_block_other_accounts(): void
    {
        $user = User::factory()->withRole(UserRole::Buyer)->create();
        $target = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/admin/users/'.$target->id)->assertForbidden();
        $this->postJson('/api/v1/admin/users', $this->adminPayload())->assertForbidden();
        $this->patchJson('/api/v1/admin/users/'.$target->id.'/status', ['status' => 'blocked'])->assertForbidden();

        $this->assertSame(UserStatus::Active, $target->fresh()->status);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_anonymous_cannot_access_admin_endpoints(): void
    {
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        $this->postJson('/api/v1/admin/users', $this->adminPayload())->assertUnauthorized();
    }

    public function test_admin_creates_another_admin_with_confirmed_current_password(): void
    {
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/users', $this->adminPayload())
            ->assertCreated()->assertJsonPath('data.roles', ['admin'])
            ->assertJsonMissingPath('data.password');

        $created = User::where('email', 'admin2@example.com')->firstOrFail();
        $this->assertTrue($created->hasRole('admin'));
        $this->assertTrue(Hash::check('a-long-admin-passphrase', $created->password));
        $this->assertSame(UserStatus::Active, $created->status);
        $this->assertSame(0, $created->tokens()->count());
    }

    public function test_admin_creation_rejects_incorrect_current_password(): void
    {
        Sanctum::actingAs(User::factory()->withRole(UserRole::Admin)->create());

        $this->postJson('/api/v1/admin/users', array_replace($this->adminPayload(), ['current_password' => 'incorrect']))
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_admin_can_block_and_unblock_but_tokens_stay_revoked(): void
    {
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        $user = User::factory()->withRole(UserRole::Ngo)->create();
        $user->createToken('existing');
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/users/'.$user->id.'/status', ['status' => 'blocked'])
            ->assertOk()->assertJsonPath('data.status', 'blocked');
        $this->assertSame(0, $user->tokens()->count());

        $this->patchJson('/api/v1/admin/users/'.$user->id.'/status', ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame(UserStatus::Active, $user->fresh()->status);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_blocked_admin_cannot_create_admins_or_read_users(): void
    {
        $admin = User::factory()->withRole(UserRole::Admin)->blocked()->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->postJson('/api/v1/admin/users', $this->adminPayload())->assertForbidden();
        $this->assertFalse(Gate::forUser($admin)->allows('createAdmin', User::class));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_admin_cannot_block_own_account(): void
    {
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/users/'.$admin->id.'/status', ['status' => 'blocked'])
            ->assertConflict();

        $this->assertSame(UserStatus::Active, $admin->fresh()->status);
    }

    public function test_admin_can_view_paginated_filtered_users_without_secrets(): void
    {
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/users?role=buyer&status=active&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $buyer->id)
            ->assertJsonPath('meta.per_page', 1)->assertJsonMissingPath('data.0.password');
        $this->getJson('/api/v1/admin/users/'.$buyer->id)
            ->assertOk()->assertJsonPath('data.id', $buyer->id);
    }

    #[TestWith(['per_page=101'])]
    #[TestWith(['per_page=-1'])]
    #[TestWith(['role=administrator'])]
    #[TestWith(['status=pending'])]
    #[TestWith(['sort=password'])]
    #[TestWith(['page=10001'])]
    public function test_invalid_filters_and_unbounded_pagination_return_422(string $query): void
    {
        Sanctum::actingAs(User::factory()->withRole(UserRole::Admin)->create());

        $this->getJson('/api/v1/admin/users?'.$query)->assertUnprocessable();
    }

    public function test_admin_status_endpoint_rejects_role_changes_and_invalid_status(): void
    {
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        $target = User::factory()->withRole(UserRole::Buyer)->create();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/users/'.$target->id.'/status', ['status' => 'pending'])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/users/'.$target->id.'/status', ['status' => 'blocked', 'role' => 'admin'])
            ->assertUnprocessable();
        $this->assertSame(UserStatus::Active, $target->fresh()->status);
        $this->assertFalse($target->fresh()->hasRole('admin'));
    }

    /** @return array<string, string> */
    private function adminPayload(): array
    {
        return [
            'name' => 'Second Admin', 'email' => 'admin2@example.com',
            'password' => 'a-long-admin-passphrase', 'password_confirmation' => 'a-long-admin-passphrase',
            'current_password' => 'password',
        ];
    }
}
