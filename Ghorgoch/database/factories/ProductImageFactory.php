<?php

namespace Database\Factories;

use App\Catalog\Models\Product;
use App\Catalog\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(), 'image_path' => 'products/'.fake()->uuid().'.jpg', 'is_primary' => false, 'sort_order' => 0,
        ];
    }
}
