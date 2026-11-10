<?php

namespace Tests\Feature;

use App\Catalog\Models\Product;
use App\Catalog\Models\ProductVariant;
use App\Users\Domain\Services\StaffPermissions;
use App\Users\Models\User;
use Database\Seeders\DemoStoreSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class DemoStoreSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_demo_catalog_has_purchasable_low_stock_and_unavailable_scenarios(): void
    {
        $this->seed(DemoStoreSeeder::class);

        $this->assertDatabaseCount('products', 8);
        $this->assertDatabaseCount('product_variants', 16);
        $this->assertDatabaseCount('product_images', 8);
        $this->assertDatabaseCount('inventory_transactions', 12);
        $this->assertSame('1290.00', ProductVariant::where('sku', 'SAMPLE-01-STANDARD')->firstOrFail()->effectivePrice());
        $this->getJson('/api/catalog/products')->assertOk()->assertJsonCount(6, 'data.products');
        $this->getJson('/api/catalog/products?availability=low_stock')->assertOk()
            ->assertJsonCount(1, 'data.products')->assertJsonPath('data.products.0.slug', 'sample-classic-everyday-backpack');
        $this->getJson('/api/catalog/products?availability=out_of_stock')->assertOk()
            ->assertJsonCount(1, 'data.products')->assertJsonPath('data.products.0.slug', 'sample-weekend-travel-bundle');
        $this->getJson('/api/catalog/products/sample-upcoming-travel-collection')->assertNotFound();
        $this->getJson('/api/catalog/products/sample-retired-home-collection')->assertNotFound();
        foreach (DB::table('product_images')->pluck('image_path') as $imagePath) {
            $this->assertFileExists(public_path($imagePath));
        }
    }

    public function test_demo_staff_have_distinct_access_levels_and_working_passwords(): void
    {
        $this->seed(DemoStoreSeeder::class);

        $permissions = app(StaffPermissions::class);
        $admin = User::where('email', 'demo-admin@example.test')->firstOrFail();
        $editor = User::where('email', 'demo-editor@example.test')->firstOrFail();
        $viewer = User::where('email', 'demo-viewer@example.test')->firstOrFail();
        $disabled = User::where('email', 'demo-disabled@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('DemoStore@12345', $admin->password));
        $this->assertTrue($permissions->allows($admin, 'staff.manage'));
        $this->assertTrue($permissions->allows($editor, 'catalog.manage'));
        $this->assertFalse($permissions->allows($editor, 'staff.manage'));
        $this->assertTrue($permissions->allows($viewer, 'catalog.view'));
        $this->assertFalse($permissions->allows($viewer, 'catalog.manage'));
        $this->assertSame([], $permissions->effective($disabled));
    }

    public function test_rerunning_preserves_accounts_catalog_edits_and_stock_without_duplicates(): void
    {
        $superAdmin = User::factory()->create(['email' => 'superadmin@example.test', 'role' => 'admin']);
        $originalPassword = $superAdmin->password;
        $this->seed(DemoStoreSeeder::class);
        $product = Product::where('slug', 'sample-everyday-travel-set')->firstOrFail();
        $product->update(['name' => 'Edited product', 'status' => 'draft']);
        $variant = ProductVariant::where('sku', 'SAMPLE-01-STANDARD')->firstOrFail();
        $variant->update(['stock_quantity' => 7]);
        $staff = User::where('email', 'demo-editor@example.test')->firstOrFail();
        $staff->forceFill(['password' => 'ChangedPassword@123', 'is_active' => false])->save();

        $this->seed(DemoStoreSeeder::class);

        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('products', 8);
        $this->assertDatabaseCount('product_variants', 16);
        $this->assertDatabaseCount('product_images', 8);
        $this->assertDatabaseCount('inventory_transactions', 12);
        $this->assertSame($originalPassword, $superAdmin->fresh()->password);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Edited product', 'status' => 'draft']);
        $this->assertSame(7, $variant->fresh()->stock_quantity);
        $this->assertFalse($staff->fresh()->is_active);
        $this->assertTrue(Hash::check('ChangedPassword@123', $staff->fresh()->password));
    }

    public function test_demo_data_is_refused_outside_local_and_testing_environments(): void
    {
        $this->app->instance('env', 'production');

        try {
            app(DemoStoreSeeder::class)->run();
            $this->fail('Demo seeding must be refused in production.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Demo store data can only be seeded locally or in tests.', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('products', 0);
    }
}
