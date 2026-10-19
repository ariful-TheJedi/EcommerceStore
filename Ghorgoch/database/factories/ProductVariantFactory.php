<?php

namespace Database\Factories;

use App\Catalog\Models\Product;
use App\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(), 'sku' => fake()->unique()->bothify('SKU-#####'), 'attributes' => [], 'price' => fake()->randomFloat(2, 1, 1000), 'stock_quantity' => 0, 'is_active' => true,
        ];
    }
}
