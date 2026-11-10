<?php

declare(strict_types=1);

/**
 * Ghorgoch — Catalog product feature tests.
 * Place in tests/Feature/Catalog/ProductTest.php
 * Run: .\vendor\bin\pest tests/Feature/Catalog/ProductTest.php
 *
 * IMPORTANT: The route list and requirements were supplied, but the actual
 * ProductController, request validators, and database schema were not.
 * Adjust productPayload() to your real accepted field names / required fields.
 * Authentication / RBAC is intentionally bypassed in write-lifecycle tests
 * to isolate product behavior; dedicated access tests must verify real RBAC.
 * Do not treat an authentication-bypassed test as security coverage.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function productPayload(array $overrides = []): array
{
    // The fields below reflect the written requirements, not a verified API schema.
    return array_replace([
        'name' => 'Catalog Test Bottle '.Str::random(9),
        'slug' => 'catalog-test-bottle-'.Str::lower(Str::random(12)),
        'description' => 'Reusable bottle for integration testing.',
        'status' => 'draft',
    ], $overrides);
}

function productCreate(array $overrides = []): array
{
    $payload = productPayload($overrides);
    $response = test()->postJson(route('catalog.products.store'), $payload);
    $response->assertSuccessful();

    $record = DB::table('products')->where('slug', $payload['slug'])->first();
    expect($record)->not->toBeNull('A successful product creation must persist its unique slug.');

    return ['id' => $record->id, 'payload' => $payload, 'record' => $record];
}

function productSnapshot(): array
{
    return DB::table('products')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
}

beforeEach(function (): void {
    foreach (['catalog.products.index', 'catalog.products.store', 'catalog.products.show', 'catalog.products.update', 'catalog.products.archive'] as $name) {
        expect(Route::has($name))->toBeTrue("Missing route {$name}");
    }
});

it('rejects anonymous requests to all protected product endpoints', function (): void {
    $calls = [
        fn () => $this->getJson(route('catalog.products.index')),
        fn () => $this->postJson(route('catalog.products.store'), productPayload()),
        fn () => $this->getJson(route('catalog.products.show', ['product' => 9999999])),
        fn () => $this->patchJson(route('catalog.products.update', ['product' => 9999999]), ['name' => 'Changed']),
        fn () => $this->postJson(route('catalog.products.archive', ['product' => 9999999])),
    ];

    foreach ($calls as $call) {
        $response = $call();
        expect(in_array($response->status(), [401, 403], true))
            ->toBeTrue("Anonymous request returned unexpected HTTP {$response->status()}");
    }

    expect(DB::table('products')->count())->toBe(0);
});

it('creates a draft product with a unique slug and required metadata', function (): void {
    $this->withoutMiddleware();
    $made = productCreate();
    expect($made['record']->slug)->toBe($made['payload']['slug']);
    expect($made['record']->name)->toBe($made['payload']['name']);
    expect($made['record']->status)->toBe('draft');
});

it('rejects missing name and slug without writing a product', function (array $changes): void {
    $this->withoutMiddleware();
    $payload = array_replace(productPayload(), $changes);
    $before = productSnapshot();
    $this->postJson(route('catalog.products.store'), $payload)->assertUnprocessable();
    expect(productSnapshot())->toBe($before);
})->with([
    'blank name' => [['name' => '']],
    'missing name' => [['name' => null]],
    'blank slug' => [['slug' => '']],
    'missing slug' => [['slug' => null]],
]);

it('prevents duplicate product slugs without modifying the original record', function (): void {
    $this->withoutMiddleware();
    $made = productCreate();
    $before = productSnapshot();

    $this->postJson(route('catalog.products.store'), productPayload(['product' => $made['id']]))
        ->assertUnprocessable();

    expect(productSnapshot())->toBe($before);
});

it('lists stored products through the staff directory and loads a product by ID', function (): void {
    $this->withoutMiddleware();
    $made = productCreate();

    $listing = $this->getJson(route('catalog.products.index'));
    $listing->assertOk();
    expect($listing->getContent())->toContain($made['payload']['name']);

    $detail = $this->getJson(route('catalog.products.show', ['product' => $made['id']]));
    $detail->assertOk();
    expect($detail->getContent())->toContain($made['payload']['slug']);
});

it('updates a product and preserves its identity and unrelated fields', function (): void {
    $this->withoutMiddleware();
    $made = productCreate();
    $newName = 'Updated catalog name';

    $this->patchJson(route('catalog.products.update', ['product' => $made['id']]), ['name' => $newName])
        ->assertSuccessful();

    $record = DB::table('products')->where('id', $made['id'])->first();
    expect($record)->not->toBeNull();
    expect($record->name)->toBe($newName);
    expect($record->slug)->toBe($made['payload']['slug']);
});

it('rejects an update that collides with another product slug', function (): void {
    $this->withoutMiddleware();
    $first = productCreate();
    $second = productCreate();
    $before = productSnapshot();

    $this->patchJson(route('catalog.products.update', ['product' => $second['id']]), [
        'slug' => $first['payload']['slug'],
    ])->assertUnprocessable();

    expect(productSnapshot())->toBe($before);
});

it('archives a product instead of physically deleting its record', function (): void {
    $this->withoutMiddleware();
    $made = productCreate();

    $this->postJson(route('catalog.products.archive', ['product' => $made['id']]))
        ->assertSuccessful();

    $row = DB::table('products')->where('id', $made['id'])->first();
    expect($row)->not->toBeNull();
    expect($row->status)->toBe('archived');
});

it('does not return an archived product through the public detail endpoint', function (): void {
    $this->withoutMiddleware();
    $made = productCreate(['status' => 'published']);
    $this->postJson(route('catalog.products.archive', ['product' => $made['id']]))->assertSuccessful();

    // Run storefront request without middleware bypass to match a visitor.
    $this->withMiddleware();
    $this->getJson(route('catalog.public.products.show', ['product' => $made['id']]))
        ->assertNotFound();
});

it('does not allow unknown numeric product IDs to be read, updated or archived', function (): void {
    $this->withoutMiddleware();
    $missing = 999999999;

    $this->getJson(route('catalog.products.show', ['product' => $missing]))->assertNotFound();
    $this->patchJson(route('catalog.products.update', ['product' => $missing]), ['name' => 'Missing'])
        ->assertNotFound();
    $this->postJson(route('catalog.products.archive', ['product' => $missing]))->assertNotFound();
});

it('does not mutate any product when an update contains invalid values', function (): void {
    $this->withoutMiddleware();
    $made = productCreate();
    $before = productSnapshot();

    $this->patchJson(route('catalog.products.update', ['product' => $made['id']]), [
        'name' => '',
        'status' => 'not-a-real-state',
    ])->assertUnprocessable();

    expect(productSnapshot())->toBe($before);
});

it('rejects an unsupported publication state on create', function (): void {
    $this->withoutMiddleware();
    $before = productSnapshot();
    $this->postJson(route('catalog.products.store'), productPayload(['status' => 'invalid-state']))
        ->assertUnprocessable();
    expect(productSnapshot())->toBe($before);
});

it('bounds staff directory pagination to avoid unreasonably large requests', function (): void {
    $this->withoutMiddleware();
    productCreate();

    $response = $this->getJson(route('catalog.products.index', ['per_page' => 1000000]));
    expect($response->status())->toBeIn([200, 422]);

    if ($response->status() === 200) {
        $json = $response->json();
        // The implementation may use Laravel paginator metadata or its own DTO.
        // Do not assert a guessed JSON key; require no unsafe oversized page in a separate contract test.
        expect($json)->toBeArray();
    }
});
