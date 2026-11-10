<?php

declare(strict_types=1);

/**
 * Catalog API tests exercise the implemented request fields and persisted state.
 */

use App\Customers\Models\Customer;
use App\Orders\Models\Order;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function catalogFlowOrder(string $reference): int
{
    $number = 'test-'.$reference;
    $existing = DB::table('orders')->where('order_number', $number)->value('id');
    if ($existing !== null) {
        return (int) $existing;
    }

    return (int) Order::factory()->create([
        'order_number' => $number,
        'customer_id' => Customer::factory(),
        'shipping_address' => '{}', 'subtotal' => 100, 'total_amount' => 100,
    ])->id;
}

function catalogFlowProduct(string $status = 'draft'): array
{
    $slug = 'flow-bottle-'.Str::lower(Str::random(16));
    $payload = [
        'name' => 'Flow Bottle '.Str::random(7),
        'slug' => $slug,
        'description' => 'Integrated catalog workflow fixture.',
        'status' => $status,
    ];
    test()->postJson(route('catalog.products.store'), $payload)->assertSuccessful();
    $row = DB::table('products')->where('slug', $slug)->first();
    expect($row)->not->toBeNull('Created product must be persisted.');

    return ['id' => (int) $row->id, 'slug' => $slug, 'name' => $payload['name']];
}

function catalogFlowVariant(int $productId, array $options, int $regular = 1000, ?int $sale = null): array
{
    $sku = 'FLOW-'.Str::upper(Str::random(15));
    $payload = [
        'sku' => $sku,
        'price' => $regular,
        'attributes' => $options,
    ];
    if ($sale !== null) {
        $payload['sale_price'] = $sale;
    }
    test()->postJson(route('catalog.products.variants.store', ['product' => $productId]), $payload)
        ->assertSuccessful();
    $table = catalogFlowVariantTable();
    $row = DB::table($table)->where('sku', $sku)->first();
    expect($row)->not->toBeNull('Created variant must be persisted.');

    return ['id' => (int) $row->id, 'sku' => $sku, 'attributes' => $options];
}

function catalogFlowVariantTable(): string
{
    foreach (['product_variants', 'variants'] as $table) {
        if (Schema::hasTable($table)) {
            return $table;
        }
    }
    throw new RuntimeException('Set catalogFlowVariantTable() to the actual variant table.');
}

function catalogFlowTaxonomy(string $kind, ?int $parentId = null): array
{
    $slug = 'flow-'.Str::lower(Str::random(14));
    $payload = ['name' => ucfirst($kind).' '.Str::random(7), 'slug' => $slug];
    if ($parentId !== null) {
        $payload['parent_id'] = $parentId;
    }
    test()->postJson(route("catalog.{$kind}.store"), $payload)->assertSuccessful();
    foreach ([$kind] as $table) {
        if (! Schema::hasTable($table)) {
            continue;
        }
        $row = DB::table($table)->where('slug', $slug)->first();
        if ($row !== null) {
            return ['id' => (int) $row->id, 'slug' => $slug];
        }
    }
    throw new RuntimeException("{$kind} creation succeeded but the taxonomy record was not found.");
}

function catalogFlowBalance(int $variantId): array
{
    foreach (['inventories', 'inventory', 'inventory_stocks', 'stock_levels', 'product_variants', 'variants'] as $table) {
        if (! Schema::hasTable($table)) {
            continue;
        }
        $variantTable = in_array($table, ['product_variants', 'variants'], true);
        $idColumn = $variantTable ? 'id' : 'variant_id';
        if (! Schema::hasColumn($table, $idColumn)) {
            continue;
        }
        $row = DB::table($table)->where($idColumn, $variantId)->first();
        if ($row === null) {
            continue;
        }
        $record = (array) $row;
        $on = collect(['on_hand', 'on_hand_stock', 'stock_on_hand', 'stock_quantity', 'quantity_on_hand'])
            ->first(fn (string $key) => array_key_exists($key, $record));
        $reserved = collect(['reserved', 'reserved_stock', 'reserved_quantity', 'quantity_reserved'])
            ->first(fn (string $key) => array_key_exists($key, $record));
        if ($on !== null && $reserved !== null) {
            $onHand = (int) $record[$on];
            $held = (int) $record[$reserved];

            return ['on_hand' => $onHand, 'reserved' => $held, 'available' => $onHand - $held];
        }
    }
    throw new RuntimeException('Adapt catalogFlowBalance() to the actual inventory table/columns.');
}

function catalogFlowAdjust(int $variantId, int $quantity): void
{
    test()->actingAs(User::factory()->create(['role' => 'admin']));
    test()->postJson(route('catalog.inventory.adjust', ['variant' => $variantId]), [
        'quantity_change' => $quantity,
        'operation_key' => 'adjust-'.Str::random(20),
        'reason' => 'Catalog full-flow inventory setup',
    ])->assertSuccessful();
}

function catalogFlowMove(string $operation, int $variantId, int $quantity, string $orderRef): TestResponse
{
    $payload = ['order_id' => catalogFlowOrder($orderRef)];
    if ($operation === 'reserve') {
        $payload['quantity'] = $quantity;
    }

    return test()->postJson(route('catalog.inventory.'.$operation, ['variant' => $variantId]), $payload);
}

function catalogFlowAssertNoPrivateFields(mixed $value): void
{
    if (! is_array($value)) {
        return;
    }
    $blocked = [
        'cost_price', 'supplier_cost', 'purchase_price', 'supplier_id',
        'supplier_name', 'supplier_phone', 'profit_margin', 'internal_margin',
        'wholesale_cost', 'cost_per_item', 'internal_notes',
    ];
    foreach ($value as $field => $child) {
        if (is_string($field)) {
            expect(strtolower($field))->not->toBeIn($blocked);
        }
        catalogFlowAssertNoPrivateFields($child);
    }
}

beforeEach(function (): void {
    foreach ([
        'catalog.products.store', 'catalog.products.index', 'catalog.products.show',
        'catalog.products.update', 'catalog.products.archive',
        'catalog.products.variants.store', 'catalog.products.variants.update',
        'catalog.products.variants.disable', 'catalog.categories.store',
        'catalog.categories.show', 'catalog.tags.store',
        'catalog.inventory.adjust', 'catalog.inventory.reserve',
        'catalog.inventory.release', 'catalog.inventory.deduct',
        'catalog.inventory.history', 'catalog.public.products.index',
        'catalog.public.products.show',
    ] as $name) {
        expect(Route::has($name))->toBeTrue("Missing route: {$name}");
    }
});

it('takes a multi-variant product from draft through publication and archival without losing records', function (): void {
    $this->withoutMiddleware();
    $product = catalogFlowProduct();
    $redSmall = catalogFlowVariant($product['id'], ['color' => 'red', 'size' => '500ml'], 1000, 850);
    $redLarge = catalogFlowVariant($product['id'], ['color' => 'red', 'size' => '1l'], 1400);
    $greenSmall = catalogFlowVariant($product['id'], ['color' => 'green', 'size' => '500ml'], 1000);

    expect(DB::table(catalogFlowVariantTable())->whereIn('id', [
        $redSmall['id'], $redLarge['id'], $greenSmall['id'],
    ])->count())->toBe(3);

    $this->getJson(route('catalog.products.show', ['product' => $product['id']]))->assertOk();
    $this->withMiddleware();
    $this->getJson(route('catalog.public.products.show', ['product' => $product['id']]))->assertNotFound();

    $this->withoutMiddleware();
    $this->patchJson(route('catalog.products.update', ['product' => $product['id']]), [
        'status' => 'published',
    ])->assertSuccessful();
    $this->withMiddleware();

    $visible = $this->getJson(route('catalog.public.products.show', ['product' => $product['id']]))->assertOk();
    expect($visible->getContent())->toContain($product['slug']);
    catalogFlowAssertNoPrivateFields($visible->json());

    $this->withoutMiddleware();
    $this->postJson(route('catalog.products.archive', ['product' => $product['id']]))->assertSuccessful();
    $this->withMiddleware();

    $this->getJson(route('catalog.public.products.show', ['product' => $product['id']]))->assertNotFound();
    expect($this->getJson(route('catalog.public.products.index'))->assertOk()->getContent())
        ->not->toContain($product['slug']);
    expect(DB::table('products')->where('id', $product['id'])->exists())->toBeTrue();
    expect(DB::table(catalogFlowVariantTable())->whereIn('id', [
        $redSmall['id'], $redLarge['id'], $greenSmall['id'],
    ])->count())->toBe(3);
});

it('keeps inventory accurate across reservations for two orders, release, and fulfillment', function (): void {
    $this->withoutMiddleware();
    $product = catalogFlowProduct('published');
    $variant = catalogFlowVariant($product['id'], ['color' => 'blue', 'capacity' => '750ml']);
    catalogFlowAdjust($variant['id'], 10);
    expect(catalogFlowBalance($variant['id']))->toBe([
        'on_hand' => 10, 'reserved' => 0, 'available' => 10,
    ]);

    catalogFlowMove('reserve', $variant['id'], 2, 'order-flow-a')->assertSuccessful();
    catalogFlowMove('reserve', $variant['id'], 4, 'order-flow-b')->assertSuccessful();
    expect(catalogFlowBalance($variant['id']))->toBe([
        'on_hand' => 10, 'reserved' => 6, 'available' => 4,
    ]);
    catalogFlowMove('release', $variant['id'], 2, 'order-flow-a')->assertSuccessful();
    expect(catalogFlowBalance($variant['id']))->toBe([
        'on_hand' => 10, 'reserved' => 4, 'available' => 6,
    ]);
    catalogFlowMove('deduct', $variant['id'], 4, 'order-flow-b')->assertSuccessful();
    expect(catalogFlowBalance($variant['id']))->toBe([
        'on_hand' => 6, 'reserved' => 0, 'available' => 6,
    ]);
    $this->getJson(route('catalog.inventory.history', ['variant' => $variant['id']]))->assertOk();
});

it('preserves every item after an invalid duplicate combination and an over-capacity reservation', function (): void {
    $this->withoutMiddleware();
    $product = catalogFlowProduct('published');
    $variant = catalogFlowVariant($product['id'], ['color' => 'red', 'size' => '500ml']);
    $beforeCount = DB::table(catalogFlowVariantTable())->where('product_id', $product['id'])->count();

    $this->postJson(route('catalog.products.variants.store', ['product' => $product['id']]), [
        'sku' => 'FLOW-'.Str::upper(Str::random(15)),
        'price' => 1000,
        'attributes' => ['size' => '500ml', 'color' => 'red'],
    ])->assertUnprocessable();
    expect(DB::table(catalogFlowVariantTable())->where('product_id', $product['id'])->count())
        ->toBe($beforeCount);

    catalogFlowAdjust($variant['id'], 3);
    $initial = catalogFlowBalance($variant['id']);
    catalogFlowMove('reserve', $variant['id'], 4, 'order-flow-over-capacity')->assertUnprocessable();
    expect(catalogFlowBalance($variant['id']))->toBe($initial);
    expect(DB::table('products')->where('id', $product['id'])->exists())->toBeTrue();
});

it('keeps two variants isolated when one is disabled and stock on the other is fulfilled', function (): void {
    $this->withoutMiddleware();
    $product = catalogFlowProduct('published');
    $active = catalogFlowVariant($product['id'], ['color' => 'green', 'size' => '500ml']);
    $disabled = catalogFlowVariant($product['id'], ['color' => 'red', 'size' => '1l']);
    catalogFlowAdjust($active['id'], 5);
    catalogFlowAdjust($disabled['id'], 8);
    $otherBalance = catalogFlowBalance($disabled['id']);

    $this->postJson(route('catalog.products.variants.disable', [
        'product' => $product['id'], 'variant' => $disabled['id'],
    ]))->assertSuccessful();
    catalogFlowMove('reserve', $active['id'], 2, 'order-flow-isolation')->assertSuccessful();
    catalogFlowMove('deduct', $active['id'], 2, 'order-flow-isolation')->assertSuccessful();

    expect(catalogFlowBalance($active['id']))->toBe([
        'on_hand' => 3, 'reserved' => 0, 'available' => 3,
    ]);
    expect(catalogFlowBalance($disabled['id']))->toBe($otherBalance);
    expect(DB::table(catalogFlowVariantTable())->where('id', $disabled['id'])->exists())->toBeTrue();
    $this->withMiddleware();
    catalogFlowAssertNoPrivateFields($this->getJson(route('catalog.public.products.show', [
        'product' => $product['id'],
    ]))->assertOk()->json());
});

it('retains product and taxonomy records across category nesting and a product lifecycle', function (): void {
    $this->withoutMiddleware();
    $parent = catalogFlowTaxonomy('categories');
    $child = catalogFlowTaxonomy('categories', $parent['id']);
    $tag = catalogFlowTaxonomy('tags');
    $product = catalogFlowProduct('published');

    $this->getJson(route('catalog.categories.show', ['item' => $parent['id']]))->assertOk();
    $this->getJson(route('catalog.categories.show', ['item' => $child['id']]))->assertOk();
    $this->getJson(route('catalog.tags.show', ['item' => $tag['id']]))->assertOk();
    $this->postJson(route('catalog.products.archive', ['product' => $product['id']]))->assertSuccessful();

    // Archiving a product must not cascade-delete unrelated taxonomy records.
    $this->getJson(route('catalog.categories.show', ['item' => $parent['id']]))->assertOk();
    $this->getJson(route('catalog.categories.show', ['item' => $child['id']]))->assertOk();
    $this->getJson(route('catalog.tags.show', ['item' => $tag['id']]))->assertOk();
    expect(DB::table('products')->where('id', $product['id'])->exists())->toBeTrue();
});
