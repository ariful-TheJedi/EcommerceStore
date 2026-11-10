<?php

declare(strict_types=1);

/**
 * Ghorgoch — Catalog product media feature tests (file 6/10).
 * Path: tests/Feature/Catalog/ProductMediaTest.php
 * Run: .\vendor\bin\pest tests/Feature/Catalog/ProductMediaTest.php
 *
 * CONTRACT NOTES (adjust the small adapter section to match your app):
 * - Upload multipart field is assumed to be `image`.
 * - Product images table is assumed to be `product_images` or `product_media`.
 * - Images are assumed to be stored on Laravel's `public` disk.
 * - Optional attributes are assumed to be `alt_text`, `sort_order`, `is_primary`.
 * - This suite tests only behavior exposed by your THREE image routes.
 * - No variant-image upload route exists in the supplied route list; variant
 *   image association must be tested against its actual API/schema separately.
 * - Lifecycle requests bypass middleware to isolate media behavior. This does
 *   NOT prove staff RBAC; anonymous access is tested with middleware enabled.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ---- Contract adapters. Change these only if the API/schema differs. ----
function mediaTestUploadField(): string
{
    return 'image';
}
function mediaTestDisk(): string
{
    return 'public';
}

function mediaTestTable(): string
{
    foreach (['product_images', 'product_media'] as $name) {
        if (Schema::hasTable($name)) {
            return $name;
        }
    }
    throw new RuntimeException('Set mediaTestTable() to the actual product image table.');
}

function mediaTestProduct(): int
{
    $slug = 'media-test-'.Str::lower(Str::random(16));
    test()->postJson(route('catalog.products.store'), [
        'name' => 'Media Test Product '.Str::random(8),
        'slug' => $slug,
        'description' => 'Testing image lifecycle.',
        'status' => 'draft',
    ])->assertSuccessful();

    $id = DB::table('products')->where('slug', $slug)->value('id');
    expect($id)->not->toBeNull('Product was not persisted.');

    return (int) $id;
}

function mediaTestImage(string $name = 'photo.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 640, 480);
}

function mediaTestUpload(int $productId, array $attributes = []): object
{
    $payload = [mediaTestUploadField() => mediaTestImage()];
    $response = test()->post(route('catalog.products.images.store', ['product' => $productId]), array_replace($payload, $attributes), ['Accept' => 'application/json']);
    $response->assertSuccessful();
    $row = DB::table(mediaTestTable())->where('product_id', $productId)->orderByDesc('id')->first();
    expect($row)->not->toBeNull('Upload succeeded but image row was missing.');

    return $row;
}

function mediaTestSnapshot(): array
{
    return DB::table(mediaTestTable())->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
}

function mediaTestFilePath(object $row): ?string
{
    foreach (['path', 'file_path', 'storage_path'] as $name) {
        if (isset($row->{$name}) && is_string($row->{$name})) {
            return $row->{$name};
        }
    }

    return null;
}

beforeEach(function (): void {
    foreach (['catalog.products.store', 'catalog.products.images.store', 'catalog.products.images.update', 'catalog.products.images.destroy'] as $name) {
        expect(Route::has($name))->toBeTrue("Missing route {$name}");
    }
});

it('blocks anonymous image upload, update and deletion', function (): void {
    $requests = [
        fn () => $this->post(route('catalog.products.images.store', ['product' => 999999]), [mediaTestUploadField() => mediaTestImage()], ['Accept' => 'application/json']),
        fn () => $this->patchJson(route('catalog.products.images.update', ['product' => 999999, 'image' => 999999]), ['alt_text' => 'test']),
        fn () => $this->deleteJson(route('catalog.products.images.destroy', ['product' => 999999, 'image' => 999999])),
    ];
    foreach ($requests as $request) {
        expect($request()->status())->toBeIn([401, 403], 'Anonymous image request was not rejected.');
    }
});

it('uploads a valid image and associates it with its product', function (): void {
    $this->withoutMiddleware();
    Storage::fake(mediaTestDisk());
    $productId = mediaTestProduct();
    $image = mediaTestUpload($productId);
    expect((int) $image->product_id)->toBe($productId);
    if ($path = mediaTestFilePath($image)) {
        Storage::disk(mediaTestDisk())->assertExists($path);
    }
});

it('supports multiple independent images on one product', function (): void {
    $this->withoutMiddleware();
    Storage::fake(mediaTestDisk());
    $productId = mediaTestProduct();
    $first = mediaTestUpload($productId);
    $second = mediaTestUpload($productId);
    expect((int) $first->id)->not->toBe((int) $second->id);
    expect(DB::table(mediaTestTable())->where('product_id', $productId)->count())->toBe(2);
});

it('rejects a missing uploaded image without persisting a media record', function (): void {
    $this->withoutMiddleware();
    $productId = mediaTestProduct();
    $before = mediaTestSnapshot();
    $this->post(route('catalog.products.images.store', ['product' => $productId]), [], ['Accept' => 'application/json'])->assertUnprocessable();
    expect(mediaTestSnapshot())->toBe($before);
});

it('rejects non-image uploads and does not write a row', function (): void {
    $this->withoutMiddleware();
    Storage::fake(mediaTestDisk());
    $productId = mediaTestProduct();
    $before = mediaTestSnapshot();
    $this->post(route('catalog.products.images.store', ['product' => $productId]), [
        mediaTestUploadField() => UploadedFile::fake()->createWithContent('fake.jpg', 'This is plain text, not an image.'),
    ], ['Accept' => 'application/json'])->assertUnprocessable();
    expect(mediaTestSnapshot())->toBe($before);
});

it('rejects unsupported file extensions without creating records', function (): void {
    $this->withoutMiddleware();
    Storage::fake(mediaTestDisk());
    $productId = mediaTestProduct();
    $before = mediaTestSnapshot();
    $this->post(route('catalog.products.images.store', ['product' => $productId]), [
        mediaTestUploadField() => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
    ], ['Accept' => 'application/json'])->assertUnprocessable();
    expect(mediaTestSnapshot())->toBe($before);
});

it('rejects oversized uploads without changing image records', function (): void {
    $this->withoutMiddleware();
    Storage::fake(mediaTestDisk());
    $productId = mediaTestProduct();
    $before = mediaTestSnapshot();
    $this->post(route('catalog.products.images.store', ['product' => $productId]), [
        // 50 MiB exceeds sensible catalog image upload limits.
        mediaTestUploadField() => UploadedFile::fake()->image('huge.jpg')->size(50 * 1024),
    ], ['Accept' => 'application/json'])->assertUnprocessable();
    expect(mediaTestSnapshot())->toBe($before);
});

it('updates image alt text without moving it to another product', function (): void {
    $this->withoutMiddleware();
    Storage::fake(mediaTestDisk());
    $productId = mediaTestProduct();
    $image = mediaTestUpload($productId);
    $this->patchJson(route('catalog.products.images.update', ['product' => $productId, 'image' => $image->id]), [
        'alt_text' => 'Reusable red bottle from the side',
    ])->assertSuccessful();
    $after = DB::table(mediaTestTable())->where('id', $image->id)->first();
    expect((int) $after->product_id)->toBe($productId);
    expect($after->alt_text)->toBe('Reusable red bottle from the side');
});

it('changes image ordering without deleting other images', function (): void {
    $this->withoutMiddleware();
    Storage::fake(mediaTestDisk());
    $productId = mediaTestProduct();
    $first = mediaTestUpload($productId);
    $second = mediaTestUpload($productId);
    $this->patchJson(route('catalog.products.images.update', ['product' => $productId, 'image' => $first->id]), [
        'sort_order' => 20,
    ])->assertSuccessful();
    expect((int) DB::table(mediaTestTable())->where('id', $first->id)->value('sort_order'))->toBe(20);
    expect(DB::table(mediaTestTable())->where('id', $second->id)->exists())->toBeTrue();
});

it('allows an image to be marked primary', function (): void {
    $this->withoutMiddleware();
    Storage::fake(mediaTestDisk());
    $productId = mediaTestProduct();
    $first = mediaTestUpload($productId);
    $second = mediaTestUpload($productId);
    $this->patchJson(route('catalog.products.images.update', ['product' => $productId, 'image' => $second->id]), [
        'is_primary' => true,
    ])->assertSuccessful();
    expect((bool) DB::table(mediaTestTable())->where('id', $second->id)->value('is_primary'))->toBeTrue();
    expect(DB::table(mediaTestTable())->where('id', $first->id)->exists())->toBeTrue();
});

it('rejects modifications to an image belonging to another product', function (): void {
    $this->withoutMiddleware();
    Storage::fake(mediaTestDisk());
    $firstProduct = mediaTestProduct();
    $otherProduct = mediaTestProduct();
    $image = mediaTestUpload($firstProduct);
    $before = mediaTestSnapshot();
    $update = $this->patchJson(route('catalog.products.images.update', ['product' => $otherProduct, 'image' => $image->id]), [
        'alt_text' => 'Unauthorized reassignment',
    ]);
    expect($update->status())->toBeIn([403, 404]);
    $delete = $this->deleteJson(route('catalog.products.images.destroy', ['product' => $otherProduct, 'image' => $image->id]));
    expect($delete->status())->toBeIn([403, 404]);
    expect(mediaTestSnapshot())->toBe($before);
});

it('returns not found for unknown product or image IDs', function (): void {
    $this->withoutMiddleware();
    $productId = mediaTestProduct();
    $this->post(route('catalog.products.images.store', ['product' => 999999999]), [mediaTestUploadField() => mediaTestImage()], ['Accept' => 'application/json'])->assertNotFound();
    $this->patchJson(route('catalog.products.images.update', ['product' => $productId, 'image' => 999999999]), ['alt_text' => 'Unknown'])->assertNotFound();
    $this->deleteJson(route('catalog.products.images.destroy', ['product' => $productId, 'image' => 999999999]))->assertNotFound();
});

it('deletes only the specified image and retains sibling images', function (): void {
    $this->withoutMiddleware();
    Storage::fake(mediaTestDisk());
    $productId = mediaTestProduct();
    $first = mediaTestUpload($productId);
    $second = mediaTestUpload($productId);
    $this->deleteJson(route('catalog.products.images.destroy', ['product' => $productId, 'image' => $first->id]))->assertSuccessful();
    expect(DB::table(mediaTestTable())->where('id', $first->id)->exists())->toBeFalse();
    expect(DB::table(mediaTestTable())->where('id', $second->id)->exists())->toBeTrue();
});
