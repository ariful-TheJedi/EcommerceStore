<?php

namespace Tests\Feature\Catalog;

use App\Auth\Domain\Services\AuthService;
use App\Catalog\Domain\Services\MediaService;
use App\Catalog\Models\Product;
use App\Catalog\Models\ProductImage;
use App\Catalog\Models\ProductVariant;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function staff(): void
    {
        $user = User::factory()->create(['role' => 'staff', 'permissions' => ['catalog.manage', 'catalog.view']]);
        $this->actingAs($user, 'staff')->withSession([
            'auth_started_at' => now()->timestamp, 'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
        ]);
    }

    public function test_images_are_decoded_stored_ordered_and_primary_selection_is_exclusive(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $this->staff();
        $base = '/api/products/'.$product->id.'/images';

        $first = $this->post($base, ['image' => UploadedFile::fake()->image('bottle.jpg'), 'alt_text' => 'Bottle', 'is_primary' => true], ['Accept' => 'application/json'])->assertCreated();
        $second = $this->post($base, ['image' => UploadedFile::fake()->image('bottle.png'), 'is_primary' => true, 'sort_order' => 1], ['Accept' => 'application/json'])->assertCreated();

        $this->assertDatabaseCount('product_images', 2);
        $this->assertDatabaseHas('product_images', ['id' => $first->json('data.image.id'), 'is_primary' => false, 'alt_text' => 'Bottle']);
        $this->assertDatabaseHas('product_images', ['id' => $second->json('data.image.id'), 'is_primary' => true]);
        $this->assertCount(2, Storage::disk('public')->allFiles('catalog'));
        $this->assertStringEndsWith('.png', ProductImage::findOrFail($first->json('data.image.id'))->image_path);
        $this->getJson('/api/products/'.$product->id)->assertOk()->assertJsonPath('data.product.images.0.id', $second->json('data.image.id'));
    }

    public function test_fake_image_content_unsupported_formats_and_oversized_uploads_are_rejected(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $this->staff();
        $url = '/api/products/'.$product->id.'/images';

        $this->post($url, ['image' => UploadedFile::fake()->createWithContent('fake.jpg', 'not an image')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($url, ['image' => UploadedFile::fake()->image('bad.gif')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($url, ['image' => UploadedFile::fake()->image('large.png')->size(6000)], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertDatabaseCount('product_images', 0);
        $this->assertCount(0, Storage::disk('public')->allFiles('catalog'));
    }

    public function test_variant_image_must_belong_to_the_product_and_failed_uploads_leave_no_files(): void
    {
        Storage::fake('public');
        $one = Product::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->staff();

        $this->post('/api/products/'.$one->id.'/images', [
            'image' => UploadedFile::fake()->image('bottle.png'), 'variant_id' => $variant->id,
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('variant_id');

        $this->assertCount(0, Storage::disk('public')->allFiles('catalog'));
    }

    public function test_variant_images_fall_back_to_product_images_and_inactive_variant_images_stay_private(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create(['status' => 'published']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $inactive = ProductVariant::factory()->create(['product_id' => $product->id, 'attributes' => ['color' => 'green'], 'is_active' => false]);
        $image = new ProductImage;
        $image->fill(['product_id' => $product->id, 'image_path' => 'catalog/fallback.png', 'alt_text' => 'Fallback']);
        $image->save();
        $hidden = new ProductImage;
        $hidden->fill(['product_id' => $product->id, 'variant_id' => $inactive->id, 'image_path' => 'catalog/hidden.png']);
        $hidden->save();

        $this->getJson('/api/catalog/products/'.$product->slug)->assertOk()
            ->assertJsonPath('data.product.variants.0.images.0.id', $image->id)
            ->assertJsonCount(1, 'data.product.images');
    }

    public function test_cleanup_keeps_shared_references_and_fresh_files_and_only_removes_old_orphans(): void
    {
        Storage::fake('public');
        $disk = Storage::disk('public');
        $disk->put('catalog/shared.png', 'shared');
        $disk->put('catalog/orphan.png', 'orphan');
        $disk->put('catalog/fresh.png', 'fresh');
        touch($disk->path('catalog/shared.png'), time() - 172800);
        touch($disk->path('catalog/orphan.png'), time() - 172800);
        $product = Product::factory()->create();
        $first = new ProductImage;
        $first->fill(['product_id' => $product->id, 'image_path' => 'catalog/shared.png']);
        $first->save();
        $second = new ProductImage;
        $second->fill(['product_id' => $product->id, 'image_path' => 'catalog/shared.png']);
        $second->save();
        app(MediaService::class)->remove($product->id, $first->id);

        $this->assertSame(1, app(MediaService::class)->cleanup());

        $disk->assertExists('catalog/shared.png');
        $disk->assertExists('catalog/fresh.png');
        $disk->assertMissing('catalog/orphan.png');
        $this->assertModelExists($second);
    }

    public function test_upload_rejects_images_exceeding_the_pixel_limit_before_writing(): void
    {
        Storage::fake('public');
        config(['catalog.upload_max_pixels' => 100]);
        $product = Product::factory()->create();
        $this->staff();

        $this->post('/api/products/'.$product->id.'/images', [
            'image' => UploadedFile::fake()->image('huge.png', 20, 20),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertCount(0, Storage::disk('public')->allFiles('catalog'));
    }

    public function test_image_is_cleaned_up_when_database_persistence_fails_after_file_storage(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $this->staff();
        ProductImage::creating(function (): void {
            throw new \RuntimeException('Simulated persistence failure.');
        });

        $this->post('/api/products/'.$product->id.'/images', [
            'image' => UploadedFile::fake()->image('bottle.png'),
        ], ['Accept' => 'application/json'])->assertStatus(500);

        $this->assertDatabaseCount('product_images', 0);
        $this->assertCount(0, Storage::disk('public')->allFiles('catalog'));
    }
}
