<?php

declare(strict_types=1);

/**
 * Catalog API tests exercise the implemented request fields and persisted state.
 */

use App\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ---- Contract adapters (change only when the real API/schema differs). ----
function inventoryTestAdjustmentPayload(int $change, string $reason = 'Inventory count reconciliation'): array
{
    // Assumption: signed adjustment delta; +N adds, -N removes stock.
    return ['quantity_change' => $change, 'reason' => $reason, 'operation_key' => 'adjust-'.Str::random(20)];
}

function inventoryTestProduct(): int
{
    test()->actingAs(User::factory()->create(['role' => 'admin']));
    $slug = 'inventory-test-'.Str::lower(Str::random(16));
    test()->postJson(route('catalog.products.store'), [
        'name' => 'Inventory Test Bottle '.Str::random(8),
        'slug' => $slug,
        'description' => 'Testing stock balances and adjustments.',
        'status' => 'draft',
    ])->assertSuccessful();

    $id = DB::table('products')->where('slug', $slug)->value('id');
    expect($id)->not->toBeNull('Product creation did not persist its slug.');

    return (int) $id;
}

function inventoryTestVariant(int $product, array $overrides = []): array
{
    $sku = 'INV-'.Str::upper(Str::random(14));
    $payload = array_replace([
        'sku' => $sku,
        'price' => 1000,
        'attributes' => ['color' => 'red', 'size' => '500ml'],
    ], $overrides);
    test()->postJson(route('catalog.products.variants.store', ['product' => $product]), $payload)
        ->assertSuccessful();

    foreach (['product_variants', 'variants'] as $table) {
        if (Schema::hasTable($table)) {
            $id = DB::table($table)->where('sku', $payload['sku'])->value('id');
            expect($id)->not->toBeNull('Variant API succeeded but SKU row was not found.');

            return ['id' => (int) $id, 'sku' => $payload['sku']];
        }
    }
    throw new RuntimeException('Set inventoryTestVariant() to your actual variant table.');
}

/** Return normalized [on_hand, reserved, available] values read FROM THE DB. */
function inventoryTestBalance(int $variantId): array
{
    // Supports common separate-inventory or variant-column designs.
    foreach (['inventories', 'inventory', 'inventory_stocks', 'stock_levels', 'product_variants', 'variants'] as $table) {
        if (! Schema::hasTable($table)) {
            continue;
        }
        $fk = in_array($table, ['product_variants', 'variants'], true) ? 'id' : 'variant_id';
        if (! Schema::hasColumn($table, $fk)) {
            continue;
        }
        $row = DB::table($table)->where($fk, $variantId)->first();
        if ($row === null) {
            continue;
        }
        $data = (array) $row;
        $onHandKey = collect(['on_hand', 'on_hand_stock', 'stock_on_hand', 'stock_quantity', 'quantity_on_hand'])
            ->first(fn ($key) => array_key_exists($key, $data));
        $reservedKey = collect(['reserved', 'reserved_stock', 'reserved_quantity', 'quantity_reserved'])
            ->first(fn ($key) => array_key_exists($key, $data));
        if ($onHandKey === null || $reservedKey === null) {
            continue;
        }
        $onHand = (int) $data[$onHandKey];
        $reserved = (int) $data[$reservedKey];

        return ['on_hand' => $onHand, 'reserved' => $reserved, 'available' => $onHand - $reserved];
    }
    throw new RuntimeException('Inventory balance columns not recognized; adapt inventoryTestBalance() to the actual schema.');
}

/** Count historical movements for a variant, independently from API response. */
function inventoryTestHistoryRows(int $variantId): array
{
    foreach (['inventory_movements', 'inventory_transactions', 'stock_movements', 'inventory_logs', 'stock_history'] as $table) {
        if (Schema::hasTable($table) && Schema::hasColumn($table, 'variant_id')) {
            return DB::table($table)->where('variant_id', $variantId)->orderBy('id')
                ->get()->map(fn ($row) => (array) $row)->all();
        }
    }
    throw new RuntimeException('Movement/history table not recognized; adapt inventoryTestHistoryRows().');
}

function inventoryTestAdjust(int $variantId, int $delta, string $reason = 'Stock count correction'): void
{
    test()->postJson(route('catalog.inventory.adjust', ['variant' => $variantId]),
        inventoryTestAdjustmentPayload($delta, $reason))->assertSuccessful();
}

beforeEach(function (): void {
    foreach (['catalog.products.store', 'catalog.products.variants.store', 'catalog.inventory.index',
        'catalog.inventory.history', 'catalog.inventory.adjust'] as $name) {
        expect(Route::has($name))->toBeTrue("Missing route {$name}");
    }
});

it('blocks anonymous stock lookup, movement history and manual adjustment', function (): void {
    $requests = [
        fn () => $this->getJson(route('catalog.inventory.index')),
        fn () => $this->getJson(route('catalog.inventory.history', ['variant' => 999999])),
        fn () => $this->postJson(route('catalog.inventory.adjust', ['variant' => 999999]), inventoryTestAdjustmentPayload(5)),
    ];
    foreach ($requests as $request) {
        expect($request()->status())->toBeIn([401, 403]);
    }
});

it('returns the staff inventory list without server errors', function (): void {
    $this->withoutMiddleware();
    $this->getJson(route('catalog.inventory.index'))->assertOk();
});

it('lists a created purchasable SKU in the inventory lookup', function (): void {
    $this->withoutMiddleware();
    $variant = inventoryTestVariant(inventoryTestProduct());
    $response = $this->getJson(route('catalog.inventory.index'))->assertOk();
    expect($response->getContent())->toContain($variant['sku']);
});

it('starts without negative balances or a reserved quantity exceeding stock', function (): void {
    $this->withoutMiddleware();
    $variant = inventoryTestVariant(inventoryTestProduct());
    $balance = inventoryTestBalance($variant['id']);
    expect($balance['on_hand'])->toBeGreaterThanOrEqual(0);
    expect($balance['reserved'])->toBeGreaterThanOrEqual(0);
    expect($balance['available'])->toBeGreaterThanOrEqual(0);
});

it('adds stock while preserving reserved stock and the available-stock equation', function (): void {
    $this->withoutMiddleware();
    $variant = inventoryTestVariant(inventoryTestProduct());
    $before = inventoryTestBalance($variant['id']);
    inventoryTestAdjust($variant['id'], 12, 'Received supplier shipment');
    $after = inventoryTestBalance($variant['id']);
    expect($after['on_hand'])->toBe($before['on_hand'] + 12);
    expect($after['reserved'])->toBe($before['reserved']);
    expect($after['available'])->toBe($after['on_hand'] - $after['reserved']);
});

it('reduces stock without affecting existing reservations', function (): void {
    $this->withoutMiddleware();
    $variant = inventoryTestVariant(inventoryTestProduct());
    inventoryTestAdjust($variant['id'], 15);
    $before = inventoryTestBalance($variant['id']);
    inventoryTestAdjust($variant['id'], -6, 'Damaged inventory write-off');
    $after = inventoryTestBalance($variant['id']);
    expect($after['on_hand'])->toBe($before['on_hand'] - 6);
    expect($after['reserved'])->toBe($before['reserved']);
    expect($after['available'])->toBe($after['on_hand'] - $after['reserved']);
});

it('rejects an adjustment that would cause negative available stock without any change', function (): void {
    $this->withoutMiddleware();
    $variant = inventoryTestVariant(inventoryTestProduct());
    $before = inventoryTestBalance($variant['id']);
    $this->postJson(route('catalog.inventory.adjust', ['variant' => $variant['id']]),
        inventoryTestAdjustmentPayload(-($before['available'] + 1), 'Unaccounted loss'))
        ->assertStatus(422);
    expect(inventoryTestBalance($variant['id']))->toBe($before);
});

it('requires a nonblank reason for manual changes and does not alter stock', function (?string $reason): void {
    $this->withoutMiddleware();
    $variant = inventoryTestVariant(inventoryTestProduct());
    $before = inventoryTestBalance($variant['id']);
    $this->postJson(route('catalog.inventory.adjust', ['variant' => $variant['id']]),
        inventoryTestAdjustmentPayload(4, $reason ?? ''))->assertUnprocessable();
    expect(inventoryTestBalance($variant['id']))->toBe($before);
})->with(['blank reason' => [''], 'whitespace reason' => ['   ']]);

it('rejects malformed adjustment quantities without changing stock', function (mixed $quantity): void {
    $this->withoutMiddleware();
    $variant = inventoryTestVariant(inventoryTestProduct());
    $before = inventoryTestBalance($variant['id']);
    $payload = inventoryTestAdjustmentPayload(5);
    $payload['quantity_change'] = $quantity; // Update this key if adapter uses a different name.
    $this->postJson(route('catalog.inventory.adjust', ['variant' => $variant['id']]), $payload)
        ->assertUnprocessable();
    expect(inventoryTestBalance($variant['id']))->toBe($before);
})->with(['text' => ['invalid'], 'fractional' => [1.5], 'missing' => [null]]);

it('records a stock adjustment and exposes its history to authorized staff', function (): void {
    $this->withoutMiddleware();
    $variant = inventoryTestVariant(inventoryTestProduct());
    $before = inventoryTestHistoryRows($variant['id']);
    inventoryTestAdjust($variant['id'], 8, 'Annual physical stock count');
    $after = inventoryTestHistoryRows($variant['id']);
    expect(count($after))->toBe(count($before) + 1);
    $new = $after[array_key_last($after)];
    expect(json_encode($new))->toContain('Annual physical stock count');
    expect($this->getJson(route('catalog.inventory.history', ['variant' => $variant['id']]))->assertOk()->getContent())
        ->toContain('Annual physical stock count');
});

it('keeps inventory balances isolated between different variants', function (): void {
    $this->withoutMiddleware();
    $product = inventoryTestProduct();
    $first = inventoryTestVariant($product);
    $second = inventoryTestVariant($product, ['attributes' => ['color' => 'green', 'size' => '1l']]);
    $secondBefore = inventoryTestBalance($second['id']);
    inventoryTestAdjust($first['id'], 9);
    expect(inventoryTestBalance($second['id']))->toBe($secondBefore);
    expect(inventoryTestBalance($first['id'])['on_hand'])->toBeGreaterThanOrEqual(9);
});

it('rejects unknown variant identifiers without changing existing inventory', function (): void {
    $this->withoutMiddleware();
    $variant = inventoryTestVariant(inventoryTestProduct());
    $before = inventoryTestBalance($variant['id']);
    $this->postJson(route('catalog.inventory.adjust', ['variant' => 999999999]),
        inventoryTestAdjustmentPayload(10))->assertNotFound();
    $this->getJson(route('catalog.inventory.history', ['variant' => 999999999]))->assertNotFound();
    expect(inventoryTestBalance($variant['id']))->toBe($before);
});
