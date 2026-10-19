<?php

namespace Database\Factories;

use App\Catalog\Models\Category;
use App\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(), 'name' => fake()->words(3, true), 'slug' => fake()->unique()->slug(3), 'status' => 'draft',
        ];
    }
}
