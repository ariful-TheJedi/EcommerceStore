<?php

namespace Database\Seeders;

use App\Catalog\Models\Category;
use App\Catalog\Models\Product;
use App\Catalog\Models\ProductImage;
use App\Catalog\Models\ProductVariant;
use App\Users\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class DemoStoreSeeder extends Seeder
{
    /**
     * Add local demo scenarios without resetting existing accounts or catalog edits.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo store data can only be seeded locally or in tests.');
        }

        DB::transaction(function (): void {
            $this->seedStaff();
            $this->seedCatalog();
        });
    }

    private function seedStaff(): void
    {
        $accounts = [
            ['name' => 'Demo Administrator', 'email' => 'demo-admin@example.test', 'role' => 'admin', 'permissions' => [], 'is_active' => true],
            ['name' => 'Demo Catalog Editor', 'email' => 'demo-editor@example.test', 'role' => 'staff', 'permissions' => ['catalog.view', 'catalog.manage', 'inventory.adjust'], 'is_active' => true],
            ['name' => 'Demo Catalog Viewer', 'email' => 'demo-viewer@example.test', 'role' => 'staff', 'permissions' => ['catalog.view'], 'is_active' => true],
            ['name' => 'Demo Disabled Staff', 'email' => 'demo-disabled@example.test', 'role' => 'staff', 'permissions' => ['catalog.view'], 'is_active' => false],
        ];

        foreach ($accounts as $account) {
            User::firstOrCreate(['email' => $account['email']], $account + [
                'password' => Hash::make('DemoStore@12345'),
                'email_verified_at' => now(),
            ]);
        }
    }

    private function seedCatalog(): void
    {
        $products = [
            ['name' => 'Everyday Travel Set', 'category' => 'Essentials', 'price' => 1490, 'stock' => 35, 'status' => 'published', 'image' => 'exp-hero.png'],
            ['name' => 'Home Comfort Collection', 'category' => 'Home', 'price' => 2490, 'stock' => 20, 'status' => 'published', 'image' => 'exp-offer.png'],
            ['name' => 'Classic Everyday Backpack', 'category' => 'Essentials', 'price' => 1890, 'stock' => 3, 'status' => 'published', 'image' => 'product4/KS142KLBB_1024x1024.webp'],
            ['name' => 'Weekend Travel Bundle', 'category' => 'Essentials', 'price' => 3290, 'stock' => 0, 'status' => 'published', 'image' => 'exp-hero.png'],
            ['name' => 'Cozy Corner Gift Set', 'category' => 'Home', 'price' => 990, 'stock' => 12, 'status' => 'published', 'image' => 'exp-offer.png'],
            ['name' => 'Daily Carry Backpack', 'category' => 'Essentials', 'price' => 2290, 'stock' => 50, 'status' => 'published', 'image' => 'product4/KS142KLBN_1024x1024.webp'],
            ['name' => 'Upcoming Travel Collection', 'category' => 'Essentials', 'price' => 1590, 'stock' => 15, 'status' => 'draft', 'image' => 'exp-hero.png'],
            ['name' => 'Retired Home Collection', 'category' => 'Home', 'price' => 790, 'stock' => 0, 'status' => 'archived', 'image' => 'exp-offer.png'],
        ];

        foreach ($products as $index => $data) {
            $imagePath = 'productimage/'.$data['image'];
            if (! File::isFile(public_path($imagePath))) {
                throw new RuntimeException("Missing demo product image: {$imagePath}");
            }

            $category = Category::firstOrCreate(['slug' => 'demo-'.Str::slug($data['category'])], [
                'name' => 'Demo '.$data['category'], 'is_active' => true,
                'description' => 'Sample collection for exploring the store.',
            ]);
            $product = Product::firstOrCreate(['slug' => 'sample-'.Str::slug($data['name'])], [
                'name' => $data['name'], 'category_id' => $category->id, 'status' => $data['status'],
                'description' => 'Demo product for testing catalog management and checkout. Local imagery is illustrative.',
            ]);
            if (! $product->wasRecentlyCreated) {
                continue;
            }
            $product->categories()->attach($category);

            foreach (['standard', 'premium'] as $optionIndex => $option) {
                $variant = ProductVariant::create([
                    'product_id' => $product->id,
                    'sku' => sprintf('SAMPLE-%02d-%s', $index + 1, strtoupper($option)),
                    'attributes' => ['edition' => $option],
                    'price' => $data['price'] + ($optionIndex * 300),
                    'sale_price' => $index === 0 ? $data['price'] - 200 + ($optionIndex * 300) : null,
                    'stock_quantity' => $data['stock'], 'reserved_quantity' => 0,
                    'low_stock_threshold' => 5, 'allow_backorders' => false, 'is_active' => true,
                ]);
                if ($data['stock'] > 0) {
                    DB::table('inventory_transactions')->insert([
                        'variant_id' => $variant->id, 'transaction_type' => 'stock_in',
                        'quantity_change' => $data['stock'], 'reserved_change' => 0,
                        'operation_key' => 'sample-opening-'.$variant->sku,
                        'note' => 'Demo store opening stock', 'created_at' => now(),
                    ]);
                }
            }

            ProductImage::create([
                'product_id' => $product->id, 'image_path' => $imagePath,
                'alt_text' => $data['name'].' sample image', 'is_primary' => true, 'sort_order' => 0,
            ]);
        }
    }
}
