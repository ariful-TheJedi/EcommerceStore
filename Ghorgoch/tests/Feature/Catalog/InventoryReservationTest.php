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

function reservationTestOrder(string $reference): int
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

function reservationTestPayload(int $quantity, string $orderRef): array
{
    return ['quantity' => $quantity, 'order_id' => reservationTestOrder($orderRef)];
}

function reservationTestProductAndVariant(): int
{
    test()->actingAs(User::factory()->create(['role' => 'admin']));
    $slug = 'reservation-'.Str::lower(Str::random(18));
    test()->postJson(route('catalog.products.store'), [
        'name' => 'Reservation Bottle '.Str::random(7),
        'slug' => $slug,
        'description' => 'Pest inventory reservation fixture',
        'status' => 'published',
    ])->assertSuccessful();

    $productId = DB::table('products')->where('slug', $slug)->value('id');
    expect($productId)->not->toBeNull();
    $sku = 'RSV-'.Str::upper(Str::random(14));
    test()->postJson(route('catalog.products.variants.store', ['product' => $productId]), [
        'sku' => $sku,
        'price' => 1000,
        'attributes' => ['color' => 'red', 'capacity' => '500ml'],
    ])->assertSuccessful();

    foreach (['product_variants', 'variants'] as $table) {
        if (Schema::hasTable($table)) {
            $variantId = DB::table($table)->where('sku', $sku)->value('id');
            expect($variantId)->not->toBeNull();

            return (int) $variantId;
        }
    }
    throw new RuntimeException('Adapt reservationTestProductAndVariant() to the actual variant table.');
}

/** Read persisted balances rather than relying on controller JSON. */
function reservationTestBalance(int $variantId): array
{
    foreach (['inventories', 'inventory', 'inventory_stocks', 'stock_levels', 'product_variants', 'variants'] as $table) {
        if (! Schema::hasTable($table)) {
            continue;
        }
        $key = in_array($table, ['product_variants', 'variants'], true) ? 'id' : 'variant_id';
        if (! Schema::hasColumn($table, $key)) {
            continue;
        }
        $row = DB::table($table)->where($key, $variantId)->first();
        if ($row === null) {
            continue;
        }
        $data = (array) $row;
        $onKey = collect(['on_hand', 'on_hand_stock', 'stock_on_hand', 'stock_quantity', 'quantity_on_hand'])
            ->first(fn (string $column) => array_key_exists($column, $data));
        $reservedKey = collect(['reserved', 'reserved_stock', 'reserved_quantity', 'quantity_reserved'])
            ->first(fn (string $column) => array_key_exists($column, $data));
        if ($onKey === null || $reservedKey === null) {
            continue;
        }
        $on = (int) $data[$onKey];
        $reserved = (int) $data[$reservedKey];

        return ['on_hand' => $on, 'reserved' => $reserved, 'available' => $on - $reserved];
    }
    throw new RuntimeException('Adapt reservationTestBalance() to the real inventory schema.');
}

function reservationTestHistoryCount(int $variantId): int
{
    foreach (['inventory_movements', 'inventory_transactions', 'stock_movements', 'inventory_logs', 'stock_history'] as $table) {
        if (Schema::hasTable($table) && Schema::hasColumn($table, 'variant_id')) {
            return DB::table($table)->where('variant_id', $variantId)->count();
        }
    }
    throw new RuntimeException('Adapt reservationTestHistoryCount() to the movement table.');
}

function reservationTestStock(int $variantId, int $quantity = 10): void
{
    test()->postJson(route('catalog.inventory.adjust', ['variant' => $variantId]), [
        'quantity_change' => $quantity,
        'operation_key' => 'adjust-'.Str::random(20),
        'reason' => 'Reserve/release/deduct integration setup',
    ])->assertSuccessful();
}

function reservationTestCall(string $action, int $variantId, int $quantity, string $orderRef): TestResponse
{
    return test()->postJson(route('catalog.inventory.'.$action, ['variant' => $variantId]),
        ($action === 'reserve' ? reservationTestPayload($quantity, $orderRef) : ['order_id' => reservationTestOrder($orderRef)]));
}

beforeEach(function (): void {
    foreach (['catalog.products.store', 'catalog.products.variants.store', 'catalog.inventory.adjust',
        'catalog.inventory.reserve', 'catalog.inventory.release', 'catalog.inventory.deduct'] as $name) {
        expect(Route::has($name))->toBeTrue("Missing named route: {$name}");
    }
});

it('blocks anonymous reserve, release and deduct requests', function (): void {
    foreach (['reserve', 'release', 'deduct'] as $action) {
        $response = $this->postJson(route('catalog.inventory.'.$action, ['variant' => 999999]),
            reservationTestPayload(1, 'test-order-1'));
        expect($response->status())->toBeIn([401, 403]);
    }
});

it('reserves stock without decreasing on-hand quantity', function (): void {
    $this->withoutMiddleware();
    $variant = reservationTestProductAndVariant();
    reservationTestStock($variant, 10);
    $before = reservationTestBalance($variant);
    reservationTestCall('reserve', $variant, 3, 'order-A')->assertSuccessful();
    $after = reservationTestBalance($variant);
    expect($after)->toBe(['on_hand' => $before['on_hand'], 'reserved' => $before['reserved'] + 3,
        'available' => $before['available'] - 3]);
});

it('rejects reservations beyond available stock without modifying balances or history', function (): void {
    $this->withoutMiddleware();
    $variant = reservationTestProductAndVariant();
    reservationTestStock($variant, 4);
    $before = reservationTestBalance($variant);
    $history = reservationTestHistoryCount($variant);
    reservationTestCall('reserve', $variant, $before['available'] + 1, 'order-too-large')
        ->assertStatus(422);
    expect(reservationTestBalance($variant))->toBe($before);
    expect(reservationTestHistoryCount($variant))->toBe($history);
});

it('permits only the last available unit to be reserved once', function (): void {
    $this->withoutMiddleware();
    $variant = reservationTestProductAndVariant();
    reservationTestStock($variant, 1);
    reservationTestCall('reserve', $variant, 1, 'order-1')->assertSuccessful();
    reservationTestCall('reserve', $variant, 1, 'order-2')->assertStatus(422);
    expect(reservationTestBalance($variant))->toBe(['on_hand' => 1, 'reserved' => 1, 'available' => 0]);
});

it('releases reserved stock and restores availability without raising on-hand stock', function (): void {
    $this->withoutMiddleware();
    $variant = reservationTestProductAndVariant();
    reservationTestStock($variant, 9);
    reservationTestCall('reserve', $variant, 4, 'order-B')->assertSuccessful();
    $before = reservationTestBalance($variant);
    reservationTestCall('release', $variant, 4, 'order-B')->assertSuccessful();
    expect(reservationTestBalance($variant))->toBe(['on_hand' => $before['on_hand'],
        'reserved' => $before['reserved'] - 4, 'available' => $before['available'] + 4]);
});

it('deducts fulfilled reservations from both on-hand and reserved quantities', function (): void {
    $this->withoutMiddleware();
    $variant = reservationTestProductAndVariant();
    reservationTestStock($variant, 8);
    reservationTestCall('reserve', $variant, 3, 'order-C')->assertSuccessful();
    $before = reservationTestBalance($variant);
    reservationTestCall('deduct', $variant, 3, 'order-C')->assertSuccessful();
    expect(reservationTestBalance($variant))->toBe(['on_hand' => $before['on_hand'] - 3,
        'reserved' => $before['reserved'] - 3, 'available' => $before['available']]);
});

it('rejects caller supplied quantities when releasing or deducting a reservation', function (string $action): void {
    $this->withoutMiddleware();
    $variant = reservationTestProductAndVariant();
    reservationTestStock($variant, 6);
    reservationTestCall('reserve', $variant, 2, 'order-D')->assertSuccessful();
    $before = reservationTestBalance($variant);
    $history = reservationTestHistoryCount($variant);
    $this->postJson(route('catalog.inventory.'.$action, ['variant' => $variant]), [
        'order_id' => reservationTestOrder('order-D'), 'quantity' => 3,
    ])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    expect(reservationTestBalance($variant))->toBe($before);
    expect(reservationTestHistoryCount($variant))->toBe($history);
})->with(['release', 'deduct']);

it('does not apply the same reservation twice', function (): void {
    $this->withoutMiddleware();
    $variant = reservationTestProductAndVariant();
    reservationTestStock($variant, 7);
    reservationTestCall('reserve', $variant, 2, 'order-E')->assertSuccessful();
    $balance = reservationTestBalance($variant);
    $history = reservationTestHistoryCount($variant);
    $duplicate = reservationTestCall('reserve', $variant, 2, 'order-E');
    $duplicate->assertOk();
    expect(reservationTestBalance($variant))->toBe($balance);
    expect(reservationTestHistoryCount($variant))->toBe($history);
});

it('does not apply the same release or deduction twice', function (string $action): void {
    $this->withoutMiddleware();
    $variant = reservationTestProductAndVariant();
    reservationTestStock($variant, 10);
    reservationTestCall('reserve', $variant, 3, 'order-F-'.$action)->assertSuccessful();
    reservationTestCall($action, $variant, 3, 'order-F-'.$action)->assertSuccessful();
    $balance = reservationTestBalance($variant);
    $history = reservationTestHistoryCount($variant);
    $duplicate = reservationTestCall($action, $variant, 3, 'order-F-'.$action);
    $duplicate->assertOk();
    expect(reservationTestBalance($variant))->toBe($balance);
    expect(reservationTestHistoryCount($variant))->toBe($history);
})->with(['release', 'deduct']);

it('rejects nonpositive, fractional and nonnumeric quantities without mutations', function (mixed $quantity): void {
    $this->withoutMiddleware();
    $variant = reservationTestProductAndVariant();
    reservationTestStock($variant, 8);
    $before = reservationTestBalance($variant);
    $history = reservationTestHistoryCount($variant);
    $payload = reservationTestPayload(1, 'order-invalid');
    $payload['quantity'] = $quantity;
    $this->postJson(route('catalog.inventory.reserve', ['variant' => $variant]), $payload)->assertUnprocessable();
    expect(reservationTestBalance($variant))->toBe($before);
    expect(reservationTestHistoryCount($variant))->toBe($history);
})->with(['zero' => [0], 'negative' => [-1], 'fractional' => [1.5], 'text' => ['invalid'], 'missing' => [null]]);

it('rejects nonexistent variants and leaves unrelated stock unchanged', function (): void {
    $this->withoutMiddleware();
    $variant = reservationTestProductAndVariant();
    reservationTestStock($variant, 5);
    $before = reservationTestBalance($variant);
    foreach (['reserve', 'release', 'deduct'] as $action) {
        reservationTestCall($action, 999999999, 1, 'order-missing')->assertNotFound();
    }
    expect(reservationTestBalance($variant))->toBe($before);
});

it('keeps independent variants isolated when one is reserved and fulfilled', function (): void {
    $this->withoutMiddleware();
    $first = reservationTestProductAndVariant();
    $second = reservationTestProductAndVariant();
    reservationTestStock($first, 7);
    reservationTestStock($second, 6);
    $secondBefore = reservationTestBalance($second);
    reservationTestCall('reserve', $first, 3, 'order-isolated')->assertSuccessful();
    reservationTestCall('deduct', $first, 3, 'order-isolated')->assertSuccessful();
    expect(reservationTestBalance($second))->toBe($secondBefore);
});

it('records a movement for every successful reservation, release, and deduction', function (): void {
    $this->withoutMiddleware();
    $variant = reservationTestProductAndVariant();
    reservationTestStock($variant, 10);
    $before = reservationTestHistoryCount($variant);
    reservationTestCall('reserve', $variant, 1, 'order-history-A')->assertSuccessful();
    reservationTestCall('release', $variant, 1, 'order-history-A')->assertSuccessful();
    reservationTestCall('reserve', $variant, 3, 'order-history-B')->assertSuccessful();
    reservationTestCall('deduct', $variant, 3, 'order-history-B')->assertSuccessful();
    expect(reservationTestHistoryCount($variant))->toBe($before + 4);
});
