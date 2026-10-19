<?php

namespace Database\Seeders;

use App\Users\Models\PasswordResetToken;
use Illuminate\Database\Seeder;

class PasswordResetTokenSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PasswordResetToken::factory()->count(10)->create();
    }
}
