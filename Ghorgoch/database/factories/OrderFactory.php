<?php

namespace Database\Factories;

use App\Orders\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => 'ORD-'.fake()->unique()->numerify('##########'),
            'customer_id' => null, 'status' => 'pending', 'payment_status' => 'unpaid',
            'shipping_address' => json_encode(['recipient_name' => 'Test Customer', 'phone' => '+8801712345678', 'district' => 'Dhaka', 'address_line1' => 'Test road']),
            'contact_snapshot' => ['name' => 'Test Customer', 'phone' => '+8801712345678'],
            'subtotal' => '200.00', 'total_amount' => '260.00', 'shipping_fee' => '60.00',
            'discount_amount' => '0.00', 'tax_amount' => '0.00', 'currency' => 'BDT',
        ];
    }
}
