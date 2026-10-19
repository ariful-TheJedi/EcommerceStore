<?php

namespace Database\Factories;

use App\Catalog\Models\ProductVariant;
use App\Orders\Models\Cart;
use App\Orders\Models\CartItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(), 'variant_id' => ProductVariant::factory(), 'quantity' => 1,
        ];
    }
}
