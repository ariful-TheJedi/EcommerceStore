<?php

namespace Database\Factories;

use App\Catalog\Models\FrontPageSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FrontPageSetting>
 */
class FrontPageSettingFactory extends Factory
{
    protected $model = FrontPageSetting::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content' => null,
            'version' => 0,
        ];
    }
}
