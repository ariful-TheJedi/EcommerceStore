<?php

namespace Database\Factories;

use App\Catalog\Models\ProductVariant;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'variant_id' => ProductVariant::factory(),
            'product_id' => fn (array $attributes): int => ProductVariant::findOrFail($attributes['variant_id'])->product_id,
            'product_name' => 'Test Product', 'sku' => fake()->unique()->bothify('ORDER-#####'),
            'variant_attributes' => [], 'quantity' => 2, 'unit_price' => '100.00',
            'line_total' => '200.00', 'discount_amount' => '0.00', 'currency' => 'BDT',
        ];
    }
}
