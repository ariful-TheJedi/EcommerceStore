<?php

namespace Tests\Feature;

use App\Auth\Domain\Services\AuthService;
use App\Catalog\Models\Product;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProductSpecificationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function signIn(): void
    {
        $user = User::factory()->create(['role' => 'staff', 'permissions' => ['catalog.view', 'catalog.manage']]);
        $this->actingAs($user, 'staff')->withSession(['auth_started_at' => now()->timestamp, 'auth_fingerprint' => app(AuthService::class)->fingerprint($user)]);
    }

    public function test_staff_can_create_and_publish_structured_specifications(): void
    {
        $this->signIn();
        $specifications = ['Material' => 'Cotton', 'Weight' => 150, 'Washable' => true, 'Warranty' => null,
            'Features' => ['Reusable', 'Lightweight'], 'Dimensions' => ['Width' => 20, 'Height' => 30]];
        $response = $this->postJson('/api/products', ['name' => 'Cotton Bag', 'slug' => 'cotton-bag', 'status' => 'published', 'specifications' => $specifications])
            ->assertCreated()->assertJsonPath('data.product.specifications', $specifications);
        $id = $response->json('data.product.id');

        $this->assertSame($specifications, Product::findOrFail($id)->specifications);
        $this->getJson('/api/products/'.$id)->assertOk()->assertJsonPath('data.product.specifications', $specifications);
        $this->getJson('/api/catalog/products/'.$id)->assertOk()->assertJsonPath('data.product.specifications', $specifications);
        $this->getJson('/api/catalog/products')->assertOk()->assertJsonPath('data.products.0.specifications', $specifications);
    }

    public function test_staff_can_replace_clear_and_preserve_omitted_specifications(): void
    {
        $this->signIn();
        $product = Product::factory()->create(['specifications' => ['Material' => 'Cotton']]);
        $this->patchJson('/api/products/'.$product->id, ['name' => 'Renamed Bag'])->assertOk()
            ->assertJsonPath('data.product.specifications.Material', 'Cotton');
        $this->patchJson('/api/products/'.$product->id, ['specifications' => ['Capacity' => '10 L']])->assertOk()
            ->assertJsonPath('data.product.specifications', ['Capacity' => '10 L']);
        $this->assertSame(['Capacity' => '10 L'], $product->fresh()->specifications);
        $this->patchJson('/api/products/'.$product->id, ['specifications' => null])->assertOk()
            ->assertJsonPath('data.product.specifications', null);
        $this->assertNull($product->fresh()->specifications);
    }

    public function test_products_without_custom_specifications_remain_readable(): void
    {
        $product = Product::factory()->create(['status' => 'published']);
        $this->getJson('/api/catalog/products/'.$product->id)->assertOk()->assertJsonPath('data.product.specifications', null);
    }

    public function test_specifications_reject_invalid_shapes_without_changing_saved_values(): void
    {
        $this->signIn();
        $product = Product::factory()->create(['specifications' => ['Material' => 'Cotton']]);
        foreach (['not JSON', 123, true, ['Unlabelled', 'List']] as $invalid) {
            $this->patchJson('/api/products/'.$product->id, ['specifications' => $invalid])->assertUnprocessable()
                ->assertJsonValidationErrors('specifications');
            $this->assertSame(['Material' => 'Cotton'], $product->fresh()->specifications);
        }
    }

    public function test_specifications_limit_property_count_size_and_nesting(): void
    {
        $this->signIn();
        $product = Product::factory()->create();
        $deep = ['Value' => 'Nested'];
        for ($level = 0; $level < 17; $level++) {
            $deep = ['Group' => $deep];
        }
        $many = [];
        for ($index = 0; $index < 101; $index++) {
            $many['Specification '.$index] = 'Value';
        }
        foreach ([$deep, $many, ['Oversized' => str_repeat('a', 65536)]] as $invalid) {
            $this->patchJson('/api/products/'.$product->id, ['specifications' => $invalid])->assertUnprocessable()
                ->assertJsonValidationErrors('specifications');
            $this->assertNull($product->fresh()->specifications);
        }
    }

    public function test_catalog_viewers_cannot_change_specifications(): void
    {
        $user = User::factory()->create(['role' => 'staff', 'permissions' => ['catalog.view']]);
        $this->actingAs($user, 'staff')->withSession(['auth_started_at' => now()->timestamp, 'auth_fingerprint' => app(AuthService::class)->fingerprint($user)]);
        $product = Product::factory()->create();

        $this->patchJson('/api/products/'.$product->id, ['specifications' => ['Material' => 'Cotton']])->assertForbidden();
        $this->assertNull($product->fresh()->specifications);
    }
}
