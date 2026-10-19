<?php

namespace Database\Factories;

use App\Customers\Models\Address;
use App\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'label' => fake()->randomElement(['Home', 'Office']),
            'recipient_name' => fake()->name(),
            'phone' => fake()->numerify('01#########'),
            'address_line1' => fake()->streetAddress(),
            'address_line2' => fake()->optional()->secondaryAddress(),
            'area' => fake()->optional()->citySuffix(),
            'city' => fake()->city(),
            'district' => fake()->optional()->city(),
            'postal_code' => fake()->optional()->postcode(),
            'country_code' => 'BD',
            'is_default' => false,
        ];
    }
}
