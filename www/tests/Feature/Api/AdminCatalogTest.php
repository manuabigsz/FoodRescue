<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCatalogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_manages_catalog_and_non_admin_cannot(): void
    {
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        Sanctum::actingAs($admin);

        $product = $this->postJson('/api/v1/admin/catalog/products', ['name' => 'Tomate'])
            ->assertCreated()->assertJsonPath('data.slug', 'tomate');
        $productId = $product->json('data.id');

        $this->patchJson('/api/v1/admin/catalog/products/'.$productId, ['active' => false])
            ->assertOk()->assertJsonPath('data.active', false);
        $this->postJson('/api/v1/admin/catalog/quality-grades', [
            'name' => 'Categoria A', 'description' => 'Boa qualidade visual.', 'sort_order' => 10,
        ])->assertCreated();

        Sanctum::actingAs(User::factory()->withRole(UserRole::Producer)->create());
        $this->postJson('/api/v1/admin/catalog/products', ['name' => 'Batata'])->assertForbidden();
    }
}
