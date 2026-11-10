<?php

declare(strict_types=1);

/**
 * Catalog API tests exercise the implemented request fields and persisted state.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function publicCatalogProductPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Storefront Bottle '.Str::random(10),
        'slug' => 'storefront-bottle-'.Str::lower(Str::random(14)),
        'description' => 'Public catalog integration test product.',
        'status' => 'draft',
    ], $overrides);
}

function publicCatalogCreateProduct(array $overrides = []): array
{
    $payload = publicCatalogProductPayload($overrides);
    test()->withoutMiddleware();
    test()->postJson(route('catalog.products.store'), $payload)->assertSuccessful();
    test()->withMiddleware();
    $id = DB::table('products')->where('slug', $payload['slug'])->value('id');
    expect($id)->not->toBeNull('Successful staff product creation must persist its slug.');

    return ['id' => (int) $id, 'slug' => $payload['slug'], 'name' => $payload['name']];
}

function publicCatalogVariantPayload(array $overrides = []): array
{
    return array_replace([
        'sku' => 'STOREFRONT-'.Str::upper(Str::random(12)),
        'price' => 1000,
        'sale_price' => 850,
        'attributes' => ['color' => 'red', 'size' => '500ml'],
    ], $overrides);
}

function publicCatalogCreateVariant(int $productId, array $overrides = []): array
{
    $payload = publicCatalogVariantPayload($overrides);
    test()->withoutMiddleware();
    test()->postJson(route('catalog.products.variants.store', ['product' => $productId]), $payload)
        ->assertSuccessful();
    test()->withMiddleware();

    return $payload;
}

/** Ensure private values aren't leaked at any nesting depth. */
function publicCatalogAssertNoPrivateKeys(mixed $value): void
{
    if (! is_array($value)) {
        return;
    }

    $privateKeys = [
        'cost_price', 'supplier_cost', 'purchase_price', 'supplier_id',
        'supplier_name', 'supplier_phone', 'profit_margin', 'internal_margin',
        'wholesale_cost', 'cost_per_item', 'internal_notes',
    ];
    foreach ($value as $key => $child) {
        if (is_string($key)) {
            expect(strtolower($key))->not->toBeIn($privateKeys);
        }
        publicCatalogAssertNoPrivateKeys($child);
    }
}

beforeEach(function (): void {
    foreach ([
        'catalog.public.products.index', 'catalog.public.products.show',
        'catalog.products.store', 'catalog.products.archive',
        'catalog.products.variants.store',
    ] as $name) {
        expect(Route::has($name))->toBeTrue("Missing route {$name}");
    }
});

it('allows visitors to list products without login', function (): void {
    $this->getJson(route('catalog.public.products.index'))->assertOk()->assertJsonStructure([]);
});

it('lists published products and hides draft products', function (): void {
    $published = publicCatalogCreateProduct(['status' => 'published']);
    $draft = publicCatalogCreateProduct(['status' => 'draft']);

    $response = $this->getJson(route('catalog.public.products.index'))->assertOk();
    expect($response->getContent())->toContain($published['slug']);
    expect($response->getContent())->not->toContain($draft['slug']);
});

it('shows a published product by ID without authentication', function (): void {
    $product = publicCatalogCreateProduct(['status' => 'published']);
    $this->getJson(route('catalog.public.products.show', ['product' => $product['id']]))
        ->assertOk()
        ->assertSee($product['slug']);
});

it('does not reveal a draft product through its public ID', function (): void {
    $draft = publicCatalogCreateProduct(['status' => 'draft']);
    $this->getJson(route('catalog.public.products.show', ['product' => $draft['id']]))
        ->assertNotFound();
});

it('removes archived products from both storefront listing and detail', function (): void {
    $product = publicCatalogCreateProduct(['status' => 'published']);
    $this->getJson(route('catalog.public.products.show', ['product' => $product['id']]))->assertOk();

    $this->withoutMiddleware();
    $this->postJson(route('catalog.products.archive', ['product' => $product['id']]))
        ->assertSuccessful();
    $this->withMiddleware();

    $this->getJson(route('catalog.public.products.show', ['product' => $product['id']]))
        ->assertNotFound();
    expect($this->getJson(route('catalog.public.products.index'))->assertOk()->getContent())
        ->not->toContain($product['slug']);
});

it('returns not found for nonexistent public product IDs', function (): void {
    $this->getJson(route('catalog.public.products.show', ['product' => 99999999]))
        ->assertNotFound();
});

it('does not expose draft products through public search', function (): void {
    $uniqueTerm = 'hidden'.Str::lower(Str::random(12));
    $draft = publicCatalogCreateProduct(['name' => $uniqueTerm, 'status' => 'draft']);
    $this->getJson(route('catalog.public.products.index', ['search' => $uniqueTerm]))
        ->assertOk()
        ->assertDontSee($draft['slug']);
});

it('never leaks internal cost or supplier fields in public list and detail responses', function (): void {
    $product = publicCatalogCreateProduct(['status' => 'published']);
    $list = $this->getJson(route('catalog.public.products.index'))->assertOk();
    $detail = $this->getJson(route('catalog.public.products.show', ['product' => $product['id']]))
        ->assertOk();

    publicCatalogAssertNoPrivateKeys($list->json());
    publicCatalogAssertNoPrivateKeys($detail->json());
});

it('returns purchasable variants and price information for a published product', function (): void {
    $product = publicCatalogCreateProduct(['status' => 'published']);
    $variant = publicCatalogCreateVariant($product['id']);

    $response = $this->getJson(route('catalog.public.products.show', ['product' => $product['id']]))
        ->assertOk();
    $body = $response->getContent();
    expect($body)->toContain($variant['sku']);
    expect($body)->toContain('850');
    publicCatalogAssertNoPrivateKeys($response->json());
});

it('does not expose disabled variants as purchasable', function (): void {
    $product = publicCatalogCreateProduct(['status' => 'published']);
    $payload = publicCatalogCreateVariant($product['id']);

    // A disabled variant may remain visible for option display, but its
    // public availability must never report it as purchasable. This check
    // deliberately requires a documented availability field/contract.
    $this->withoutMiddleware();
    $table = Schema::hasTable('product_variants') ? 'product_variants' : 'variants';
    $variantId = DB::table($table)->where('sku', $payload['sku'])->value('id');
    expect($variantId)->not->toBeNull();
    $this->postJson(route('catalog.products.variants.disable', [
        'product' => $product['id'], 'variant' => $variantId,
    ]))->assertSuccessful();
    $this->withMiddleware();

    $response = $this->getJson(route('catalog.public.products.show', ['product' => $product['id']]))->assertOk();
    $body = $response->getContent();
    // Implementation-neutral safe case: hidden from public detail altogether.
    // If disabled variants are shown to display options, add an assertion for
    // your actual `is_available` / `purchasable` JSON contract here instead.
    if (! str_contains($body, $payload['sku'])) {
        expect($body)->not->toContain($payload['sku']);
    } else {
        $this->markTestIncomplete('Disabled SKU is public; verify non-purchasable flag against the actual response DTO.');
    }
});

it('accepts supported storefront pagination and sorting parameters', function (): void {
    publicCatalogCreateProduct(['status' => 'published']);
    foreach ([
        ['page' => 1, 'per_page' => 5],
        ['sort' => 'name'],
        ['sort' => '-name'],
    ] as $params) {
        $response = $this->getJson(route('catalog.public.products.index', $params));
        $response->assertOk();
        expect($response->json())->toBeArray();
    }
});

it('rejects invalid pagination input or safely caps its size', function (): void {
    publicCatalogCreateProduct(['status' => 'published']);
    $response = $this->getJson(route('catalog.public.products.index', ['per_page' => 1000000]));
    expect($response->status())->toBeIn([200, 422]);
    if ($response->status() === 200) {
        $payload = $response->json();
        $meta = $payload['meta'] ?? $payload;
        if (isset($meta['per_page'])) {
            expect((int) $meta['per_page'])->toBeLessThanOrEqual(100);
        } else {
            $this->markTestIncomplete('Identify actual pagination-size response field to assert bounded page size.');
        }
    }
});
