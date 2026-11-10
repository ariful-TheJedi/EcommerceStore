<?php

namespace Tests\Feature;

use App\Catalog\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_receive_only_active_categories_in_storefront_order(): void
    {
        Category::factory()->create(['name' => 'Hidden category', 'is_active' => false]);
        $last = Category::factory()->create(['name' => 'Last category', 'sort_order' => 5]);
        $first = Category::factory()->create(['name' => 'First category', 'sort_order' => 1]);

        $this->getJson(route('catalog.public.categories.index'))->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $first->id)
            ->assertJsonPath('data.items.1.id', $last->id)
            ->assertJsonMissing(['name' => 'Hidden category'])
            ->assertJsonMissingPath('data.items.0.image_path');
    }

    public function test_guests_can_resolve_an_active_category_without_products(): void
    {
        $category = Category::factory()->create(['description' => 'A collection ready for new products.']);

        $this->getJson(route('catalog.public.categories.show', $category->slug))->assertOk()
            ->assertJsonPath('data.item.id', $category->id)
            ->assertJsonPath('data.item.name', $category->name)
            ->assertJsonPath('data.item.description', 'A collection ready for new products.');
    }

    public function test_inactive_and_unknown_categories_return_404(): void
    {
        $category = Category::factory()->create(['is_active' => false]);

        $this->getJson(route('catalog.public.categories.show', $category->slug))->assertNotFound();
        $this->getJson(route('catalog.public.categories.show', 'unknown-category'))->assertNotFound();
    }

    public function test_public_category_routes_do_not_allow_writes(): void
    {
        $this->postJson('/api/catalog/categories', ['name' => 'Unauthorized category'])->assertMethodNotAllowed();

        $this->assertDatabaseCount('categories', 0);
    }
}
