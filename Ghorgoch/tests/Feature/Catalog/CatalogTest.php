<?php

namespace Tests\Feature\Catalog;

use App\Auth\Domain\Services\AuthService;
use App\Catalog\Models\Category;
use App\Catalog\Models\Product;
use App\Catalog\Models\ProductVariant;
use App\Catalog\Models\Tag;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function staff(array $permissions = ['catalog.view', 'catalog.manage']): User
    {
        $user = User::factory()->create(['role' => 'staff', 'permissions' => $permissions]);
        $this->actingAs($user, 'staff')->withSession([
            'auth_started_at' => now()->timestamp, 'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
        ]);

        return $user;
    }

    public function test_staff_can_create_update_list_and_archive_a_product(): void
    {
        $this->staff();
        $category = Category::factory()->create();
        $tag = Tag::factory()->create();

        $response = $this->postJson('/api/products', [
            'name' => 'Bottle', 'slug' => 'bottle', 'status' => 'draft',
            'category_ids' => [$category->id], 'tag_ids' => [$tag->id],
        ])->assertCreated()->assertJsonPath('data.product.status', 'draft');
        $id = $response->json('data.product.id');
        $this->patchJson('/api/products/'.$id, ['name' => 'Water Bottle'])->assertOk()->assertJsonPath('data.product.name', 'Water Bottle');
        $this->getJson('/api/products?status=draft&search=water')->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->postJson('/api/products/'.$id.'/archive')->assertOk()->assertJsonPath('data.product.status', 'archived');
        $this->assertDatabaseHas('product_categories', ['product_id' => $id, 'category_id' => $category->id]);
        $this->assertDatabaseHas('product_tags', ['product_id' => $id, 'tag_id' => $tag->id]);
    }

    public function test_bottle_variants_have_distinct_options_and_duplicate_combinations_fail(): void
    {
        $product = Product::factory()->create();
        $this->staff();
        foreach ([
            ['red', '500 ml'], ['red', '1 litre'], ['green', '500 ml'],
        ] as $index => [$color, $capacity]) {
            $this->postJson('/api/products/'.$product->id.'/variants', [
                'sku' => 'BOTTLE-'.$index, 'attributes' => ['color' => $color, 'capacity' => $capacity], 'price' => '250.00',
            ])->assertCreated();
        }

        $this->postJson('/api/products/'.$product->id.'/variants', [
            'sku' => 'DUPLICATE', 'attributes' => ['capacity' => ' 500 ML ', 'Color' => ' RED '], 'price' => 300,
        ])->assertUnprocessable()->assertJsonValidationErrors('attributes');

        $this->assertDatabaseCount('product_variants', 3);
    }

    public function test_simple_product_uses_one_empty_option_variant_and_stock_cannot_be_injected(): void
    {
        $product = Product::factory()->create();
        $this->staff();
        $url = '/api/products/'.$product->id.'/variants';

        $this->postJson($url, ['sku' => 'SIMPLE', 'price' => 100])->assertCreated();
        $this->postJson($url, ['sku' => 'SECOND-SIMPLE', 'price' => 100])->assertUnprocessable();
        $this->postJson($url, ['sku' => 'INJECTED', 'price' => 100, 'stock_quantity' => 999])
            ->assertUnprocessable()->assertJsonValidationErrors('stock_quantity');
        $this->assertDatabaseCount('product_variants', 1);
    }

    public function test_public_catalog_excludes_drafts_archives_inactive_variants_and_costs(): void
    {
        Product::factory()->create(['slug' => 'draft', 'status' => 'draft']);
        Product::factory()->create(['slug' => 'archived', 'status' => 'archived']);
        $product = Product::factory()->create(['slug' => 'published', 'status' => 'published']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'attributes' => ['color' => 'red'], 'cost_price' => 50, 'price' => 100, 'stock_quantity' => 2]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'attributes' => ['color' => 'green'], 'is_active' => false]);

        $response = $this->getJson('/api/catalog/products')->assertOk()
            ->assertJsonPath('data.pagination.total', 1)->assertJsonCount(1, 'data.products.0.variants')
            ->assertJsonPath('data.products.0.variants.0.currency', 'BDT');
        $response->assertJsonMissingPath('data.products.0.variants.0.cost_price');
        $this->assertStringNotContainsString('cost_price', $response->getContent());
        $this->getJson('/api/catalog/products/draft')->assertNotFound();
        $this->getJson('/api/catalog/products/archived')->assertNotFound();
    }

    public function test_sale_dates_and_price_filters_use_the_same_effective_price(): void
    {
        $this->freezeTime();
        $product = Product::factory()->create(['status' => 'published']);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id, 'price' => 100, 'sale_price' => 70,
            'sale_starts_at' => now()->subDay(), 'sale_ends_at' => now()->addDay(),
        ]);

        $this->getJson('/api/catalog/products?min_price=60&max_price=80&sort=price')
            ->assertOk()->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.products.0.variants.0.effective_price', '70.00');
        $this->travel(2)->days();
        $this->getJson('/api/catalog/products?max_price=80')->assertOk()->assertJsonPath('data.pagination.total', 0);
        $this->assertSame('100.00', $variant->fresh()->effectivePrice());
    }

    public function test_regular_and_sale_price_validation_checks_existing_values_on_partial_updates(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 100, 'sale_price' => 80]);
        $this->staff();
        $url = '/api/products/'.$product->id.'/variants/'.$variant->id;

        $this->patchJson($url, ['price' => 70])->assertUnprocessable()->assertJsonValidationErrors('sale_price');
        $this->patchJson($url, ['sale_price' => -1])->assertUnprocessable();
        $this->patchJson($url, ['sale_price' => 100])->assertUnprocessable();
        $this->patchJson($url, ['sale_starts_at' => '2026-10-20', 'sale_ends_at' => '2026-10-19'])->assertUnprocessable();
        $this->assertSame('100.00', $variant->fresh()->price);
    }

    public function test_category_cycles_are_rejected_and_deletion_never_deletes_products(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $product = Product::factory()->create(['category_id' => $child->id]);
        $product->categories()->attach($child);
        $this->staff();

        $this->patchJson('/api/categories/'.$parent->id, ['parent_id' => $child->id])->assertUnprocessable();
        $this->patchJson('/api/categories/'.$child->id, ['parent_id' => $child->id])->assertUnprocessable();
        $this->patchJson('/api/categories/'.$parent->id, ['parent_id' => (string) $parent->id])->assertUnprocessable();
        $this->deleteJson('/api/categories/'.$parent->id)->assertConflict();
        $this->deleteJson('/api/categories/'.$child->id)->assertOk();
        $this->assertModelExists($product);
        $this->assertNull($product->fresh()->category_id);
        $this->assertDatabaseMissing('product_categories', ['category_id' => $child->id]);
    }

    public function test_category_and_tag_filters_and_tag_removal_keep_products(): void
    {
        $category = Category::factory()->create(['slug' => 'bottles']);
        $tag = Tag::factory()->create(['slug' => 'eco']);
        $product = Product::factory()->create(['status' => 'published']);
        $product->categories()->attach($category);
        $product->tags()->attach($tag);
        Product::factory()->create(['status' => 'published']);

        $this->getJson('/api/catalog/products?category=bottles&tag=eco')->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->staff();
        $this->deleteJson('/api/tags/'.$tag->id)->assertOk();
        $this->assertModelExists($product);
    }

    public function test_routes_require_explicit_permissions_and_payloads_cannot_grant_authority(): void
    {
        $this->getJson('/api/products')->assertUnauthorized();
        $this->staff(['catalog.view']);
        $this->getJson('/api/products')->assertOk();
        $this->postJson('/api/products', ['name' => 'Denied', 'slug' => 'denied'])->assertForbidden();
        $this->getJson('/api/catalog/products?sort=password')->assertUnprocessable();
        $this->getJson('/api/catalog/products?per_page=101')->assertUnprocessable();
        $this->getJson('/api/catalog/products?status=draft')->assertUnprocessable();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_variant_ids_are_scoped_to_their_product_and_identifiers_are_unique(): void
    {
        $one = Product::factory()->create();
        $two = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $one->id, 'sku' => 'UNIQUE']);
        $this->staff();

        $this->patchJson('/api/products/'.$two->id.'/variants/'.$variant->id, ['price' => 90])->assertNotFound();
        $this->postJson('/api/products/'.$two->id.'/variants', ['sku' => 'UNIQUE', 'price' => 100])->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->postJson('/api/products', ['name' => 'Duplicate', 'slug' => $one->slug])->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_products_and_variants_have_no_destructive_delete_routes(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->staff();

        $this->deleteJson('/api/products/'.$product->id)->assertMethodNotAllowed();
        $this->deleteJson('/api/products/'.$product->id.'/variants/'.$variant->id)->assertMethodNotAllowed();
        $this->assertModelExists($product);
        $this->assertModelExists($variant);
    }

    public function test_public_catalog_query_count_is_bounded_when_loading_more_products(): void
    {
        foreach (range(1, 5) as $index) {
            $product = Product::factory()->create(['status' => 'published']);
            ProductVariant::factory()->create(['product_id' => $product->id]);
        }
        DB::enableQueryLog();

        $this->getJson('/api/catalog/products')->assertOk()->assertJsonCount(5, 'data.products');

        $this->assertLessThanOrEqual(8, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_product_is_not_out_of_stock_when_another_active_variant_is_available(): void
    {
        $product = Product::factory()->create(['status' => 'published']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'attributes' => ['color' => 'red'], 'stock_quantity' => 0]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'attributes' => ['color' => 'green'], 'stock_quantity' => 2]);

        $this->getJson('/api/catalog/products?availability=out_of_stock')->assertOk()->assertJsonPath('data.pagination.total', 0);
        $this->getJson('/api/catalog/products?availability=in_stock')->assertOk()->assertJsonPath('data.pagination.total', 1);
    }

    public function test_category_membership_can_be_cleared_including_the_legacy_primary_category(): void
    {
        $product = Product::factory()->create();
        $product->categories()->attach($product->category_id);
        $this->staff();

        $this->patchJson('/api/products/'.$product->id, ['category_ids' => []])->assertOk()->assertJsonPath('data.product.categories', []);

        $this->assertNull($product->fresh()->category_id);
        $this->assertDatabaseMissing('product_categories', ['product_id' => $product->id]);
    }

    public function test_combined_price_and_availability_filters_match_the_same_variant(): void
    {
        $product = Product::factory()->create(['status' => 'published']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'attributes' => ['color' => 'red'], 'price' => 100, 'stock_quantity' => 0]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'attributes' => ['color' => 'green'], 'price' => 500, 'stock_quantity' => 2]);

        $this->getJson('/api/catalog/products?max_price=200&availability=in_stock')->assertOk()->assertJsonPath('data.pagination.total', 0);
        $this->getJson('/api/catalog/products?max_price=600&availability=in_stock')->assertOk()->assertJsonPath('data.pagination.total', 1);
    }

    public function test_sale_windows_accept_timezone_offsets_and_store_utc(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20T12:30:00Z'));
        $product = Product::factory()->create();
        $this->staff();

        $this->postJson('/api/products/'.$product->id.'/variants', [
            'sku' => 'OFFSET', 'price' => 100, 'sale_price' => 80,
            'sale_starts_at' => '2026-10-20T18:00:00+06:00', 'sale_ends_at' => '2026-10-20T19:00:00+06:00',
        ])->assertCreated()->assertJsonPath('data.product.variants.0.effective_price', '80.00')
            ->assertJsonPath('data.product.variants.0.sale_starts_at', '2026-10-20T12:00:00.000000Z');
    }

    public function test_staff_catalog_writes_require_a_session_csrf_token(): void
    {
        $this->staff();
        $this->withSession(['_token' => 'catalog-session-token']);
        $this->app->instance('env', 'local');
        $payload = ['name' => 'CSRF Bottle', 'slug' => 'csrf-bottle'];

        $this->postJson('/api/products', $payload)->assertStatus(419);
        $this->postJson('/api/products', $payload, ['X-CSRF-TOKEN' => 'catalog-session-token'])->assertCreated();

        $this->assertDatabaseCount('products', 1);
    }
}
