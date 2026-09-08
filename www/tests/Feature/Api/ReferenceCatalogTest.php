<?php

namespace Tests\Feature\Api;

use App\Models\AgriculturalProduct;
use App\Models\QualityGrade;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReferenceCatalogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_producer_lists_active_products_and_quality_grades_without_admin_access(): void
    {
        $active = AgriculturalProduct::factory()->create(['name' => 'Tomate', 'active' => true]);
        $inactive = AgriculturalProduct::factory()->create(['name' => 'Descontinuado', 'active' => false]);
        $grade = QualityGrade::factory()->create(['name' => 'Tipo B', 'active' => true]);
        QualityGrade::factory()->create(['name' => 'Obsoleto', 'active' => false]);

        Sanctum::actingAs(User::factory()->withRole(UserRole::Producer)->create());

        $products = $this->getJson('/api/v1/catalog/products')->assertOk()->json('data');
        $this->assertSame([$active->id], array_column($products, 'id'));
        $this->assertNotContains($inactive->id, array_column($products, 'id'));

        $grades = $this->getJson('/api/v1/catalog/quality-grades')->assertOk()->json('data');
        $this->assertSame([$grade->id], array_column($grades, 'id'));
    }

    public function test_reference_catalog_requires_authentication(): void
    {
        $this->getJson('/api/v1/catalog/products')->assertUnauthorized();
        $this->getJson('/api/v1/catalog/quality-grades')->assertUnauthorized();
    }

    public function test_administrative_catalog_management_remains_restricted_to_admin(): void
    {
        Sanctum::actingAs(User::factory()->withRole(UserRole::Producer)->create());

        $this->getJson('/api/v1/admin/catalog/products')->assertForbidden();
    }
}
