<?php

namespace Tests\Feature;

use App\Auth\Domain\Services\AuthService;
use App\Catalog\Models\Category;
use App\Catalog\Models\Product;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryIndependenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_removing_a_category_from_taxonomy_preserves_it_and_its_products(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user, 'staff')->withSession([
            'auth_started_at' => now()->timestamp,
            'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
        ]);
        $parent = Category::factory()->create(['is_taxonomy' => true]);
        $category = Category::factory()->create(['parent_id' => $parent->id, 'is_taxonomy' => false]);
        $product = Product::factory()->create();
        $category->products()->attach($product);

        $this->patchJson('/api/categories/'.$category->id, ['parent_id' => null])->assertOk();

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'parent_id' => null, 'is_taxonomy' => false]);
        $this->assertDatabaseHas('product_categories', ['category_id' => $category->id, 'product_id' => $product->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('categories', ['id' => $parent->id, 'is_taxonomy' => true]);

        $this->patchJson('/api/categories/'.$category->id, ['parent_id' => $parent->id])->assertOk();
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'parent_id' => $parent->id, 'is_taxonomy' => false]);
    }
}
