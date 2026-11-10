<?php

declare(strict_types=1);

/**
 * Variant API fixtures use price and attributes. Business tests bypass middleware.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function variantTestProductPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Variant Test Bottle '.Str::random(8),
        'slug' => 'variant-test-bottle-'.Str::lower(Str::random(14)),
        'description' => 'A product for variant integration tests.',
        'status' => 'draft',
    ], $overrides);
}

function variantTestCreateProduct(array $overrides = []): int
{
    $payload = variantTestProductPayload($overrides);
    test()->postJson(route('catalog.products.store'), $payload)->assertSuccessful();
    $id = DB::table('products')->where('slug', $payload['slug'])->value('id');
    expect($id)->not->toBeNull('Product creation succeeded but the product was not persisted.');

    return (int) $id;
}

/**
 * Variant API fixtures use price and attributes. Business tests bypass middleware.
 */
function variantTestPayload(array $options = ['color' => 'red', 'size' => '500ml'], array $overrides = []): array
{
    return array_replace([
        'sku' => 'VTEST-'.Str::upper(Str::random(14)),
        'price' => 1000,
        'attributes' => $options,
    ], $overrides);
}

/**
 * Variant API fixtures use price and attributes. Business tests bypass middleware.
 */
function variantTestTable(): string
{
    foreach (['product_variants', 'variants'] as $table) {
        if (Schema::hasTable($table)) {
            return $table;
        }
    }
    throw new RuntimeException('Could not find product_variants or variants table; set the real name in variantTestTable().');
}

function variantTestRowBySku(string $sku): ?object
{
    return DB::table(variantTestTable())->where('sku', $sku)->first();
}

function variantTestCreate(int $productId, array $options = ['color' => 'red', 'size' => '500ml'], array $overrides = []): array
{
    $payload = variantTestPayload($options, $overrides);
    test()->postJson(route('catalog.products.variants.store', ['product' => $productId]), $payload)
        ->assertSuccessful();
    $row = variantTestRowBySku($payload['sku']);
    expect($row)->not->toBeNull('Variant creation succeeded but its SKU is missing from the database.');

    return ['id' => (int) $row->id, 'payload' => $payload, 'row' => $row];
}

function variantTestSnapshot(): array
{
    return DB::table(variantTestTable())->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
}

beforeEach(function (): void {
    foreach (['catalog.products.store', 'catalog.products.variants.store',
        'catalog.products.variants.update', 'catalog.products.variants.disable'] as $name) {
        expect(Route::has($name))->toBeTrue("Missing route {$name}");
    }
});

it('rejects anonymous variant creation, modification, and disabling', function (): void {
    foreach ([
        fn () => $this->postJson(route('catalog.products.variants.store', ['product' => 999999]), variantTestPayload()),
        fn () => $this->patchJson(route('catalog.products.variants.update', ['product' => 999999, 'variant' => 999999]), ['sku' => 'CHANGED']),
        fn () => $this->postJson(route('catalog.products.variants.disable', ['product' => 999999, 'variant' => 999999])),
    ] as $request) {
        $response = $request();
        expect($response->status())->toBeIn([401, 403], 'Protected variant route allowed an anonymous request.');
    }
});

it('creates three distinct purchasable combinations for the same bottle', function (): void {
    $this->withoutMiddleware();
    $product = variantTestCreateProduct();

    $first = variantTestCreate($product, ['color' => 'red', 'size' => '500ml']);
    $second = variantTestCreate($product, ['color' => 'red', 'size' => '1l']);
    $third = variantTestCreate($product, ['color' => 'green', 'size' => '500ml']);

    expect(array_unique([$first['id'], $second['id'], $third['id']]))->toHaveCount(3);
    expect(array_unique([$first['payload']['sku'], $second['payload']['sku'], $third['payload']['sku']]))->toHaveCount(3);
});

it('rejects duplicate option combinations within the same product without writing a row', function (): void {
    $this->withoutMiddleware();
    $product = variantTestCreateProduct();
    variantTestCreate($product, ['color' => 'red', 'size' => '500ml']);
    $before = variantTestSnapshot();

    $this->postJson(route('catalog.products.variants.store', ['product' => $product]),
        variantTestPayload(['color' => 'red', 'size' => '500ml']))->assertUnprocessable();

    expect(variantTestSnapshot())->toBe($before);
});

it('permits the same combination on different products', function (): void {
    $this->withoutMiddleware();
    $one = variantTestCreateProduct();
    $two = variantTestCreateProduct();

    $first = variantTestCreate($one, ['color' => 'red', 'size' => '500ml']);
    $second = variantTestCreate($two, ['color' => 'red', 'size' => '500ml']);
    expect($first['id'])->not->toBe($second['id']);
});

it('rejects a duplicate SKU across products without altering existing variants', function (): void {
    $this->withoutMiddleware();
    $one = variantTestCreateProduct();
    $two = variantTestCreateProduct();
    $first = variantTestCreate($one);
    $before = variantTestSnapshot();

    $this->postJson(route('catalog.products.variants.store', ['product' => $two]),
        variantTestPayload(['color' => 'blue', 'size' => '1l'], ['sku' => $first['payload']['sku']]))
        ->assertUnprocessable();
    expect(variantTestSnapshot())->toBe($before);
});

it('rejects absent or blank SKU before persisting a variant', function (array $overrides): void {
    $this->withoutMiddleware();
    $product = variantTestCreateProduct();
    $before = variantTestSnapshot();

    $this->postJson(route('catalog.products.variants.store', ['product' => $product]),
        variantTestPayload(['color' => 'blue', 'size' => '750ml'], $overrides))->assertUnprocessable();
    expect(variantTestSnapshot())->toBe($before);
})->with([
    'blank sku' => [['sku' => '']],
    'null sku' => [['sku' => null]],
]);

it('rejects invalid regular prices without persisting a variant', function (mixed $price): void {
    $this->withoutMiddleware();
    $product = variantTestCreateProduct();
    $before = variantTestSnapshot();

    $this->postJson(route('catalog.products.variants.store', ['product' => $product]),
        variantTestPayload(['color' => 'blue', 'size' => '750ml'], ['price' => $price]))
        ->assertUnprocessable();
    expect(variantTestSnapshot())->toBe($before);
})->with([
    'negative' => [-1],
    'non numeric' => ['not-a-price'],
]);

it('updates one variant without changing its SKU or its siblings', function (): void {
    $this->withoutMiddleware();
    $product = variantTestCreateProduct();
    $first = variantTestCreate($product);
    $second = variantTestCreate($product, ['color' => 'green', 'size' => '1l']);
    $siblingBefore = (array) variantTestRowBySku($second['payload']['sku']);

    $this->patchJson(route('catalog.products.variants.update', [
        'product' => $product, 'variant' => $first['id'],
    ]), ['price' => 1250])->assertSuccessful();

    expect(variantTestRowBySku($first['payload']['sku']))->not->toBeNull();
    expect((array) variantTestRowBySku($second['payload']['sku']))->toBe($siblingBefore);
});

it('disables a variant without physically removing it', function (): void {
    $this->withoutMiddleware();
    $product = variantTestCreateProduct();
    $variant = variantTestCreate($product);

    $this->postJson(route('catalog.products.variants.disable', [
        'product' => $product, 'variant' => $variant['id'],
    ]))->assertSuccessful();

    $after = variantTestRowBySku($variant['payload']['sku']);
    expect($after)->not->toBeNull('Disabled variants must retain their history and identity.');
    if (property_exists($after, 'is_active')) {
        expect((bool) $after->is_active)->toBeFalse();
    } elseif (property_exists($after, 'status')) {
        expect($after->status)->toBeIn(['disabled', 'inactive', 'archived']);
    } else {
        test()->fail('No active/status field exists to verify that disabling took effect.');
    }
});

it('rejects unknown product and variant identifiers without creating data', function (): void {
    $this->withoutMiddleware();
    $product = variantTestCreateProduct();
    $before = variantTestSnapshot();

    $this->postJson(route('catalog.products.variants.store', ['product' => 999999999]), variantTestPayload())
        ->assertNotFound();
    $this->patchJson(route('catalog.products.variants.update', ['product' => $product, 'variant' => 999999999]),
        ['price' => 999])->assertNotFound();
    $this->postJson(route('catalog.products.variants.disable', ['product' => $product, 'variant' => 999999999]))
        ->assertNotFound();
    expect(variantTestSnapshot())->toBe($before);
});

it('prevents updating or disabling another product’s variant through a mismatched URL', function (): void {
    $this->withoutMiddleware();
    $owner = variantTestCreateProduct();
    $other = variantTestCreateProduct();
    $variant = variantTestCreate($owner);
    $before = variantTestSnapshot();

    $this->patchJson(route('catalog.products.variants.update', ['product' => $other, 'variant' => $variant['id']]),
        ['price' => 111])->assertNotFound();
    $this->postJson(route('catalog.products.variants.disable', ['product' => $other, 'variant' => $variant['id']]))
        ->assertNotFound();
    expect(variantTestSnapshot())->toBe($before);
});

it('does not modify the variant when an update supplies an invalid price', function (): void {
    $this->withoutMiddleware();
    $product = variantTestCreateProduct();
    $variant = variantTestCreate($product);
    $before = variantTestSnapshot();

    $this->patchJson(route('catalog.products.variants.update', [
        'product' => $product, 'variant' => $variant['id'],
    ]), ['price' => -50])->assertUnprocessable();
    expect(variantTestSnapshot())->toBe($before);
});
