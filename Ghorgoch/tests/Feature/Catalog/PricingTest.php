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
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function pricingProduct(array $overrides = []): int
{
    $payload = array_replace([
        'name' => 'Pricing Bottle '.Str::random(8),
        'slug' => 'pricing-bottle-'.Str::lower(Str::random(14)),
        'description' => 'Pricing test fixture.',
        'status' => 'draft',
    ], $overrides);

    test()->postJson(route('catalog.products.store'), $payload)->assertSuccessful();
    $id = DB::table('products')->where('slug', $payload['slug'])->value('id');
    expect($id)->not->toBeNull('Product was not persisted.');

    return (int) $id;
}

function pricingTable(): string
{
    foreach (['product_variants', 'variants'] as $name) {
        if (Schema::hasTable($name)) {
            return $name;
        }
    }
    throw new RuntimeException('Update pricingTable() to match your actual variants table.');
}

function pricingVariantPayload(array $overrides = []): array
{
    return array_replace([
        'sku' => 'PRICE-'.Str::upper(Str::random(12)),
        'price' => 1000,
        'attributes' => ['color' => 'red', 'size' => '500ml'],
    ], $overrides);
}

function pricingCreateVariant(int $product, array $overrides = []): array
{
    $payload = pricingVariantPayload($overrides);
    test()->postJson(route('catalog.products.variants.store', ['product' => $product]), $payload)
        ->assertSuccessful();
    $row = DB::table(pricingTable())->where('sku', $payload['sku'])->first();
    expect($row)->not->toBeNull('Variant creation did not persist its SKU.');

    return ['id' => (int) $row->id, 'sku' => $payload['sku']];
}

function pricingRow(string $sku): object
{
    $row = DB::table(pricingTable())->where('sku', $sku)->first();
    expect($row)->not->toBeNull('Expected variant is missing.');

    return $row;
}

function pricingSnapshot(): array
{
    return DB::table(pricingTable())->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
}

function pricingUpdate(int $product, int $variant, array $payload): TestResponse
{
    return test()->patchJson(route('catalog.products.variants.update', [
        'product' => $product, 'variant' => $variant,
    ]), $payload);
}

beforeEach(function (): void {
    foreach (['catalog.products.store', 'catalog.products.variants.store',
        'catalog.products.variants.update', 'catalog.products.show'] as $name) {
        expect(Route::has($name))->toBeTrue("Missing route {$name}");
    }
});

it('persists a regular price for a purchasable variant', function (): void {
    $this->withoutMiddleware();
    $product = pricingProduct();
    $variant = pricingCreateVariant($product, ['price' => 1275]);
    expect((float) pricingRow($variant['sku'])->price)->toBe(1275.0);
});

it('accepts a valid sale price lower than regular price', function (): void {
    $this->withoutMiddleware();
    $product = pricingProduct();
    $variant = pricingCreateVariant($product, ['price' => 1000, 'sale_price' => 799]);
    $row = pricingRow($variant['sku']);
    expect(property_exists($row, 'sale_price'))->toBeTrue('Expected variant-level sale_price field.');
    expect((float) $row->sale_price)->toBe(799.0);
});

it('supports a variant without a sale price', function (): void {
    $this->withoutMiddleware();
    $product = pricingProduct();
    $variant = pricingCreateVariant($product, ['price' => 950]);
    $row = pricingRow($variant['sku']);
    expect((float) $row->price)->toBe(950.0);
    if (property_exists($row, 'sale_price')) {
        expect($row->sale_price)->toBeNull();
    }
});

it('rejects invalid regular prices without inserting the variant', function (mixed $regular): void {
    $this->withoutMiddleware();
    $product = pricingProduct();
    $before = pricingSnapshot();
    $this->postJson(route('catalog.products.variants.store', ['product' => $product]),
        pricingVariantPayload(['price' => $regular]))->assertUnprocessable();
    expect(pricingSnapshot())->toBe($before);
})->with([
    'negative price' => [-0.01],
    'nonnumeric string' => ['not-a-number'],
    'array value' => [[1, 2]],
]);

it('rejects invalid sale prices without inserting the variant', function (mixed $sale): void {
    $this->withoutMiddleware();
    $product = pricingProduct();
    $before = pricingSnapshot();
    $this->postJson(route('catalog.products.variants.store', ['product' => $product]),
        pricingVariantPayload(['price' => 1000, 'sale_price' => $sale]))->assertUnprocessable();
    expect(pricingSnapshot())->toBe($before);
})->with([
    'negative sale' => [-1],
    'equal to regular' => [1000],
    'above regular' => [1001],
    'not numeric' => ['free'],
]);

it('updates prices on one variant without changing sibling prices', function (): void {
    $this->withoutMiddleware();
    $product = pricingProduct();
    $first = pricingCreateVariant($product);
    $second = pricingCreateVariant($product, [
        'sku' => 'PRICE-'.Str::upper(Str::random(12)),
        'attributes' => ['color' => 'blue', 'size' => '1l'],
        'price' => 1500,
    ]);
    $siblingBefore = (array) pricingRow($second['sku']);
    pricingUpdate($product, $first['id'], ['price' => 1250, 'sale_price' => 1100])
        ->assertSuccessful();
    $updated = pricingRow($first['sku']);
    expect((float) $updated->price)->toBe(1250.0);
    expect((float) $updated->sale_price)->toBe(1100.0);
    expect((array) pricingRow($second['sku']))->toBe($siblingBefore);
});

it('rejects a sale price made invalid by a regular-price update and leaves state unchanged', function (): void {
    $this->withoutMiddleware();
    $product = pricingProduct();
    $variant = pricingCreateVariant($product, ['price' => 1000, 'sale_price' => 800]);
    $before = pricingSnapshot();
    pricingUpdate($product, $variant['id'], ['price' => 700])->assertUnprocessable();
    expect(pricingSnapshot())->toBe($before);
});

it('rejects a price update with a negative or nonnumeric value without mutating data', function (mixed $value): void {
    $this->withoutMiddleware();
    $product = pricingProduct();
    $variant = pricingCreateVariant($product);
    $before = pricingSnapshot();
    pricingUpdate($product, $variant['id'], ['price' => $value])->assertUnprocessable();
    expect(pricingSnapshot())->toBe($before);
})->with(['negative' => [-10], 'nonnumeric' => ['invalid']]);

it('does not allow modifying an unrelated product variant price', function (): void {
    $this->withoutMiddleware();
    $owner = pricingProduct();
    $other = pricingProduct();
    $variant = pricingCreateVariant($owner);
    $before = pricingSnapshot();
    pricingUpdate($other, $variant['id'], ['price' => 2000])->assertNotFound();
    expect(pricingSnapshot())->toBe($before);
});

it('does not expose common internal pricing fields on public product detail', function (): void {
    $this->withoutMiddleware();
    $product = pricingProduct(['status' => 'published']);
    pricingCreateVariant($product);
    $response = $this->getJson(route('catalog.public.products.show', ['product' => $product]));
    $response->assertOk();

    $json = $response->json();
    $walk = function (mixed $value) use (&$walk): void {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            if (is_string($key)) {
                expect(strtolower($key))->not->toBeIn([
                    'cost_price', 'supplier_cost', 'purchase_price', 'profit_margin',
                    'internal_margin', 'supplier_id', 'supplier_name',
                ]);
            }
            $walk($child);
        }
    };
    $walk($json);
});
