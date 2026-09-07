<?php

namespace Tests\Feature\Api;

use App\Models\PlatformSetting;
use App\Models\User;
use App\UserRole;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTimeoutSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_updates_operational_timeouts(): void
    {
        $this->seed(PermissionsSeeder::class);
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/settings/timeouts', [
            'shipping_quotation_timeout_minutes' => 180,
            'payment_timeout_minutes' => 20,
        ])->assertOk()
            ->assertJsonPath('data.shipping_quotation_timeout_minutes', 180)
            ->assertJsonPath('data.payment_timeout_minutes', 20);

        $this->assertSame(180, PlatformSetting::integer(PlatformSetting::SHIPPING_QUOTATION_TIMEOUT, 240));
        $this->assertSame(20, PlatformSetting::integer(PlatformSetting::PAYMENT_TIMEOUT, 15));
    }
}
