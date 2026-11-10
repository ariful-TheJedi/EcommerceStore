<?php

namespace Tests\Feature;

use App\Auth\Domain\Services\AuthService;
use App\Catalog\Models\Category;
use App\Catalog\Models\Product;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CategoryImageTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(array $permissions = ['catalog.manage', 'catalog.view']): void
    {
        $user = User::factory()->create(['role' => 'staff', 'permissions' => $permissions]);
        $this->actingAs($user, 'staff')->withSession([
            'auth_started_at' => now()->timestamp,
            'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
        ]);
        Storage::fake(config('catalog.media_disk'));
    }

    public function test_categories_and_parent_taxonomies_accept_supported_images_in_shared_storage(): void
    {
        $this->signIn();
        foreach (['png', 'jpg', 'jpeg', 'svg'] as $extension) {
            $category = Category::factory()->create(['is_taxonomy' => $extension === 'svg']);
            $image = $extension === 'svg'
                ? UploadedFile::fake()->createWithContent('icon.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"><path d="M0 0h24v24z"/></svg>')
                : UploadedFile::fake()->image('icon.'.$extension)->size(2048);
            $response = $this->postJson('/api/categories/'.$category->id.'/image', ['image' => $image])->assertOk();
            $path = $category->fresh()->image_path;
            $this->assertStringStartsWith('catalog/', $path);
            Storage::disk(config('catalog.media_disk'))->assertExists($path);
            $response->assertJsonPath('data.item.image_url', Storage::disk(config('catalog.media_disk'))->url($path));
            $this->getJson('/api/catalog/categories/'.$category->slug)->assertOk()->assertJsonPath('data.item.image_url', Storage::disk(config('catalog.media_disk'))->url($path));
        }
    }

    public function test_invalid_uploads_preserve_the_current_photo(): void
    {
        $this->signIn();
        $category = Category::factory()->create(['image_path' => 'catalog/original.png']);
        Storage::disk(config('catalog.media_disk'))->put($category->image_path, 'original');
        $files = [
            UploadedFile::fake()->image('large.png')->size(2049),
            UploadedFile::fake()->image('wrong.webp'),
            UploadedFile::fake()->createWithContent('fake.png', 'not an image'),
            UploadedFile::fake()->createWithContent('script.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('event.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'),
            UploadedFile::fake()->createWithContent('external.svg', '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://example.com/image.png"/></svg>'),
        ];
        foreach ($files as $image) {
            $this->postJson('/api/categories/'.$category->id.'/image', ['image' => $image])->assertUnprocessable()->assertJsonValidationErrors('image');
            $this->assertSame('catalog/original.png', $category->fresh()->image_path);
            $this->assertCount(1, Storage::disk(config('catalog.media_disk'))->allFiles('catalog'));
        }
    }

    public function test_removing_an_icon_preserves_the_category_and_product_assignments(): void
    {
        $this->signIn();
        $category = Category::factory()->create(['image_path' => 'catalog/icon.png']);
        $product = Product::factory()->create();
        $category->products()->attach($product);
        Storage::disk(config('catalog.media_disk'))->put('catalog/icon.png', 'image');

        $this->deleteJson('/api/categories/'.$category->id.'/image')->assertOk()
            ->assertJsonPath('data.item.image_path', null)
            ->assertJsonPath('data.item.image_url', null);

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'image_path' => null]);
        $this->assertDatabaseHas('product_categories', ['category_id' => $category->id, 'product_id' => $product->id]);
        $this->deleteJson('/api/categories/'.$category->id.'/image')->assertOk();
    }

    public function test_read_only_staff_cannot_remove_category_photos(): void
    {
        $this->signIn(['catalog.view']);
        $category = Category::factory()->create(['image_path' => 'catalog/icon.png']);
        Storage::disk(config('catalog.media_disk'))->put('catalog/icon.png', 'image');

        $this->deleteJson('/api/categories/'.$category->id.'/image')->assertForbidden();

        $this->assertSame('catalog/icon.png', $category->fresh()->image_path);
        Storage::disk(config('catalog.media_disk'))->assertExists('catalog/icon.png');
    }

    public function test_read_only_staff_cannot_upload_category_photos(): void
    {
        $this->signIn(['catalog.view']);
        $category = Category::factory()->create();
        $this->postJson('/api/categories/'.$category->id.'/image', ['image' => UploadedFile::fake()->image('icon.png')])->assertForbidden();
        $this->assertNull($category->fresh()->image_path);
        $this->assertSame([], Storage::disk(config('catalog.media_disk'))->allFiles('catalog'));
    }
}
