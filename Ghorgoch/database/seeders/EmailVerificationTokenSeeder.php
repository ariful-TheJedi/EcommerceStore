<?php

namespace Database\Seeders;

use App\Users\Models\EmailVerificationToken;
use Illuminate\Database\Seeder;

class EmailVerificationTokenSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        EmailVerificationToken::factory()->count(10)->create();
    }
}
