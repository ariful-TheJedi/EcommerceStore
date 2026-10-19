<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use SplFileInfo;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_seeds_related_demo_records_and_uses_existing_product_images(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach ([
            'users',
            'customers',
            'email_verification_tokens',
            'password_reset_tokens',
            'addresses',
            'categories',
            'products',
            'product_variants',
            'product_images',
            'tags',
            'product_tags',
            'carts',
            'cart_items',
            'coupons',
            'orders',
            'order_items',
            'payments',
            'inventory_transactions',
        ] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), "Expected {$table} to contain demo records.");
        }

        foreach (DB::table('product_images')->pluck('image_path') as $imagePath) {
            $this->assertFileExists(public_path($imagePath));
        }

        $expectedImages = collect(File::allFiles(public_path('productimage')))
            ->filter(fn (SplFileInfo $file): bool => in_array(strtolower($file->getExtension()), ['jpg', 'jpeg', 'png', 'webp', 'gif', 'jfif'], true))
            ->count();
        $this->assertSame($expectedImages, DB::table('product_images')->count());
        $this->assertSame(0, DB::table('product_images')->whereNull('variant_id')->count());
    }

    public function test_running_the_demo_seeder_more_than_once_does_not_duplicate_records(): void
    {
        $this->seed(DatabaseSeeder::class);
        $tables = [
            'users',
            'customers',
            'email_verification_tokens',
            'password_reset_tokens',
            'addresses',
            'categories',
            'products',
            'product_variants',
            'product_images',
            'tags',
            'product_tags',
            'carts',
            'cart_items',
            'coupons',
            'orders',
            'order_items',
            'payments',
            'inventory_transactions',
        ];
        $initialCounts = [];
        foreach ($tables as $table) {
            $initialCounts[$table] = DB::table($table)->count();
        }

        $this->seed(DatabaseSeeder::class);

        foreach ($tables as $table) {
            $this->assertSame($initialCounts[$table], DB::table($table)->count(), "Expected {$table} to remain idempotent.");
        }
    }
}
