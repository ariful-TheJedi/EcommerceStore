<?php

declare(strict_types=1);

/**
 * Save as tests/Feature/Orders/CartTest.php
 * Run: .\vendor\bin\pest tests/Feature/Orders/CartTest.php
 *
 * Contract tests based on the supplied Orders API reference (default cart limits:
 * 50 variants, 100 units per variant, 30-day lifetime, BDT).
 * Catalog fixture routes follow the previously passing CatalogAutoFlowTest.
 * Middleware is bypassed ONLY while creating Catalog fixtures, never for carts.
 * Laravel normally bypasses CSRF in testing; the dedicated CSRF case restores it.
 * Source code and a Laravel runtime were not available when generating this file.
 */

use App\Auth\Domain\Services\AuthService;
use App\Customers\Models\Customer;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

function ordersCartVariantTable(): string
{
    foreach (['product_variants', 'variants'] as $table) {
        if (Schema::hasTable($table)) {
            return $table;
        }
    }

    throw new RuntimeException('Catalog variant table not found. Update ordersCartVariantTable().');
}

function ordersCartFixture(int $stock = 200, int $price = 1000, ?int $sale = null, string $status = 'published'): array
{
    $test = test();
    $slug = 'cart-test-'.Str::lower(Str::random(16));
    $sku = 'CART-'.Str::upper(Str::random(16));
    $test->withoutMiddleware();

    try {
        $test->postJson(route('catalog.products.store'), [
            'name' => 'Cart Test Bottle', 'slug' => $slug, 'status' => $status,
            'description' => 'Orders cart test fixture.',
        ])->assertSuccessful();
        $product = DB::table('products')->where('slug', $slug)->first();
        expect($product)->not->toBeNull();

        $payload = ['sku' => $sku, 'price' => $price, 'attributes' => ['color' => 'red', 'size' => '500ml']];
        if ($sale !== null) {
            $payload['sale_price'] = $sale;
        }
        $test->postJson(route('catalog.products.variants.store', ['product' => $product->id]), $payload)
            ->assertSuccessful();
        $variant = DB::table(ordersCartVariantTable())->where('sku', $sku)->first();
        expect($variant)->not->toBeNull();

        if ($stock > 0) {
            DB::table('product_variants')->where('id', $variant->id)->update(['stock_quantity' => $stock]);
        }

        return ['product_id' => (int) $product->id, 'variant_id' => (int) $variant->id, 'sku' => $sku];
    } finally {
        $test->withMiddleware();
    }
}

function ordersCartBalance(int $variantId): array
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
        $on = collect(['on_hand', 'on_hand_stock', 'stock_on_hand', 'stock_quantity', 'quantity_on_hand'])
            ->first(fn ($name) => array_key_exists($name, $data));
        $reserved = collect(['reserved', 'reserved_stock', 'reserved_quantity', 'quantity_reserved'])
            ->first(fn ($name) => array_key_exists($name, $data));
        if ($on !== null && $reserved !== null) {
            return ['on_hand' => (int) $data[$on], 'reserved' => (int) $data[$reserved]];
        }
    }

    throw new RuntimeException('Inventory balance not found. Update ordersCartBalance() to match Catalog.');
}

function ordersCartLogin(User $user): void
{
    Auth::forgetGuards();
    test()->actingAs($user, 'customer')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
    ]);
}

function ordersCartCustomer(): Customer
{
    $user = User::factory()->create(['role' => 'customer', 'is_active' => true]);

    return Customer::factory()->create(['user_id' => $user->id])->load('user');
}

function ordersCartAmount(mixed $value, string $expected): void
{
    // Tests compare API money representations; production arithmetic must use minor units.
    expect(is_numeric($value))->toBeTrue();
    expect(number_format((float) $value, 2, '.', ''))->toBe($expected);
}

it('exposes public cart and order health routes', function (): void {
    $this->getJson('/api/cart/health')->assertOk();
    $this->getJson('/api/orders/health')->assertOk()->assertJsonPath('module', 'orders');
});

it('returns a private empty guest cart without creating an order or payment', function (): void {
    $orders = DB::table('orders')->count();
    $payments = DB::table('payments')->count();
    $response = $this->getJson('/api/cart')->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonCount(0, 'data.cart.items')
        ->assertJsonPath('data.cart.can_checkout', false)
        ->assertJsonPath('data.cart.currency', 'BDT');
    foreach (['subtotal', 'discount_amount', 'estimated_total'] as $field) {
        ordersCartAmount($response->json('data.cart.'.$field), '0.00');
    }
    expect(DB::table('orders')->count())->toBe($orders);
    expect(DB::table('payments')->count())->toBe($payments);
});

it('adds a guest item and returns catalog identity quantity and server prices', function (): void {
    $item = ordersCartFixture();
    $response = $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 2])
        ->assertSuccessful()->assertJsonCount(1, 'data.cart.items')
        ->assertJsonPath('data.cart.items.0.variant_id', $item['variant_id'])
        ->assertJsonPath('data.cart.items.0.product_id', $item['product_id'])
        ->assertJsonPath('data.cart.items.0.sku', $item['sku'])
        ->assertJsonPath('data.cart.items.0.quantity', 2)
        ->assertJsonPath('data.cart.items.0.available', true)
        ->assertJsonPath('data.cart.can_checkout', true);
    ordersCartAmount($response->json('data.cart.items.0.unit_price'), '1000.00');
    ordersCartAmount($response->json('data.cart.items.0.line_total'), '2000.00');
    ordersCartAmount($response->json('data.cart.estimated_total'), '2000.00');
    $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.cart.items.0.quantity', 2);
});

it('merges duplicate additions and sets rather than increments patched quantities', function (): void {
    $item = ordersCartFixture();
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 2])->assertSuccessful();
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 3])
        ->assertSuccessful()->assertJsonCount(1, 'data.cart.items')->assertJsonPath('data.cart.items.0.quantity', 5);
    $response = $this->patchJson('/api/cart/items/'.$item['variant_id'], ['quantity' => 4])
        ->assertOk()->assertJsonPath('data.cart.items.0.quantity', 4);
    ordersCartAmount($response->json('data.cart.subtotal'), '4000.00');
});

it('keeps distinct variants separate and removes only the selected line', function (): void {
    $first = ordersCartFixture(price: 1000);
    $second = ordersCartFixture(price: 500);
    $this->postJson('/api/cart/items', ['variant_id' => $first['variant_id'], 'quantity' => 2])->assertSuccessful();
    $response = $this->postJson('/api/cart/items', ['variant_id' => $second['variant_id'], 'quantity' => 3])
        ->assertSuccessful()->assertJsonCount(2, 'data.cart.items');
    ordersCartAmount($response->json('data.cart.subtotal'), '3500.00');
    $response = $this->deleteJson('/api/cart/items/'.$first['variant_id'])->assertOk()
        ->assertJsonCount(1, 'data.cart.items')->assertJsonPath('data.cart.items.0.variant_id', $second['variant_id']);
    ordersCartAmount($response->json('data.cart.estimated_total'), '1500.00');
    $this->deleteJson('/api/cart/items/'.$second['variant_id'])->assertOk()
        ->assertJsonCount(0, 'data.cart.items')->assertJsonPath('data.cart.can_checkout', false);
});

it('uses catalog sale prices and recalculates existing lines after catalog price changes', function (): void {
    $item = ordersCartFixture(price: 1000, sale: 850);
    $response = $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 2])->assertSuccessful();
    ordersCartAmount($response->json('data.cart.items.0.unit_price'), '850.00');
    ordersCartAmount($response->json('data.cart.estimated_total'), '1700.00');
    DB::table(ordersCartVariantTable())->where('id', $item['variant_id'])->update(['sale_price' => 800]);
    $response = $this->getJson('/api/cart')->assertOk();
    ordersCartAmount($response->json('data.cart.items.0.unit_price'), '800.00');
    ordersCartAmount($response->json('data.cart.estimated_total'), '1600.00');
});

it('rejects invalid quantities on additions and updates without changing the existing line', function (mixed $quantity): void {
    $item = ordersCartFixture();
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 2])->assertSuccessful();
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => $quantity])
        ->assertUnprocessable()->assertJsonValidationErrors('quantity');
    $this->patchJson('/api/cart/items/'.$item['variant_id'], ['quantity' => $quantity])
        ->assertUnprocessable()->assertJsonValidationErrors('quantity');
    $this->getJson('/api/cart')->assertOk()->assertJsonCount(1, 'data.cart.items')->assertJsonPath('data.cart.items.0.quantity', 2);
})->with(['zero' => [0], 'negative' => [-1], 'fraction' => [1.5], 'null' => [null], 'text' => ['abc'], 'array' => [[2]], 'over limit' => [101]]);

it('requires variant and quantity fields', function (): void {
    $this->postJson('/api/cart/items', [])->assertUnprocessable()->assertJsonValidationErrors(['variant_id', 'quantity']);
    $item = ordersCartFixture();
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 1])->assertSuccessful();
    $this->patchJson('/api/cart/items/'.$item['variant_id'], [])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.cart.items.0.quantity', 1);
});

it('enforces the quantity limit on the merged total atomically', function (): void {
    $item = ordersCartFixture();
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 100])->assertSuccessful();
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 1])->assertUnprocessable();
    $this->getJson('/api/cart')->assertOk()->assertJsonCount(1, 'data.cart.items')->assertJsonPath('data.cart.items.0.quantity', 100);
});

it('rejects unknown variants without adding a line', function (): void {
    $id = (int) DB::table(ordersCartVariantTable())->max('id') + 10000;
    $this->postJson('/api/cart/items', ['variant_id' => $id, 'quantity' => 1])->assertUnprocessable();
    $this->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'data.cart.items');
});

it('rejects products that are not published', function (): void {
    $item = ordersCartFixture(status: 'draft');
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 1])->assertUnprocessable();
    $this->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'data.cart.items');
});

it('rejects insufficient stock on additions duplicate additions and updates', function (): void {
    $item = ordersCartFixture(stock: 3);
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 4])->assertUnprocessable();
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 2])->assertSuccessful();
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 2])->assertUnprocessable();
    $this->patchJson('/api/cart/items/'.$item['variant_id'], ['quantity' => 4])->assertUnprocessable();
    $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.cart.items.0.quantity', 2);
});

it('marks an existing line unavailable when its product becomes unpublished', function (): void {
    $item = ordersCartFixture();
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 1])->assertSuccessful();
    DB::table('products')->where('id', $item['product_id'])->update(['status' => 'draft']);
    $this->getJson('/api/cart')->assertOk()->assertJsonCount(1, 'data.cart.items')
        ->assertJsonPath('data.cart.items.0.available', false)->assertJsonPath('data.cart.can_checkout', false);
    $this->deleteJson('/api/cart/items/'.$item['variant_id'])->assertOk()->assertJsonCount(0, 'data.cart.items');
});

it('never changes stock or creates orders or payments during cart operations', function (): void {
    $item = ordersCartFixture();
    $balance = ordersCartBalance($item['variant_id']);
    $orders = DB::table('orders')->count();
    $payments = DB::table('payments')->count();
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 2])->assertSuccessful();
    $this->getJson('/api/cart')->assertOk();
    $this->patchJson('/api/cart/items/'.$item['variant_id'], ['quantity' => 3])->assertOk();
    $this->deleteJson('/api/cart/items/'.$item['variant_id'])->assertOk();
    expect(ordersCartBalance($item['variant_id']))->toBe($balance);
    expect(DB::table('orders')->count())->toBe($orders);
    expect(DB::table('payments')->count())->toBe($payments);
});

it('rejects client prices totals and ownership fields without changing the cart', function (string $field, mixed $value): void {
    $item = ordersCartFixture();
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 2])->assertSuccessful();
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 1, $field => $value])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->patchJson('/api/cart/items/'.$item['variant_id'], ['quantity' => 1, $field => $value])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $response = $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.cart.items.0.quantity', 2);
    ordersCartAmount($response->json('data.cart.estimated_total'), '2000.00');
})->with([
    ['price', 1], ['unit_price', 1], ['total', 1], ['customer_id', 999], ['cart_id', 999], ['guest_token', 'chosen-token'],
]);

it('keeps registered customer carts isolated across accounts', function (): void {
    $item = ordersCartFixture();
    $owner = ordersCartCustomer();
    $other = ordersCartCustomer();
    ordersCartLogin($owner->user);
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 2])->assertSuccessful();
    ordersCartLogin($other->user);
    $this->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'data.cart.items');
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 1])->assertSuccessful();
    $this->deleteJson('/api/cart/items/'.$item['variant_id'])->assertOk();
    ordersCartLogin($owner->user);
    $this->getJson('/api/cart')->assertOk()->assertJsonCount(1, 'data.cart.items')->assertJsonPath('data.cart.items.0.quantity', 2);
});

it('expires an abandoned cart without affecting inventory and allows a new purchase', function (): void {
    $item = ordersCartFixture();
    $balance = ordersCartBalance($item['variant_id']);
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 2])->assertSuccessful();
    $this->travel(31)->days();
    try {
        $this->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'data.cart.items')->assertJsonPath('data.cart.can_checkout', false);
        expect(ordersCartBalance($item['variant_id']))->toBe($balance);
        $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 1])
            ->assertSuccessful()->assertJsonPath('data.cart.items.0.quantity', 1);
    } finally {
        $this->travelBack();
    }
});

it('refreshes cart lifetime when quantity is updated', function (): void {
    $item = ordersCartFixture();
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 1])->assertSuccessful();
    try {
        $this->travel(20)->days();
        $this->patchJson('/api/cart/items/'.$item['variant_id'], ['quantity' => 2])->assertOk();
        $this->travel(15)->days();
        $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.cart.items.0.quantity', 2);
    } finally {
        $this->travelBack();
    }
});

it('requires csrf for cart writes outside the testing environment', function (): void {
    $item = ordersCartFixture();
    $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 1])->assertSuccessful();
    $originalEnvironment = $this->app->environment();
    $this->app->instance('env', 'local');
    try {
        $this->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => 1])->assertStatus(419);
        $this->patchJson('/api/cart/items/'.$item['variant_id'], ['quantity' => 2])->assertStatus(419);
        $this->deleteJson('/api/cart/items/'.$item['variant_id'])->assertStatus(419);
    } finally {
        $this->app->instance('env', $originalEnvironment);
    }
    $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.cart.items.0.quantity', 1);
});
