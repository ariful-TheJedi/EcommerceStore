<?php

declare(strict_types=1);

/**
 * Location: tests/Feature/Orders/CartMergeTest.php
 * Run: .\vendor\bin\pest tests/Feature/Orders/CartMergeTest.php
 * Standalone Pest file: no dependency on CartTest.php or other generated files.
 * Based on the supplied Orders API reference and previous Catalog fixture pattern.
 * Requires documented default BDT fees (Dhaka 60, other districts 120) and cart limits.
 * Middleware bypass is limited to Catalog fixture creation.
 * Application source/runtime were unavailable; these contracts have not been run here.
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

function ordersMergeVariantTable(): string
{
    foreach (['product_variants', 'variants'] as $table) {
        if (Schema::hasTable($table)) {
            return $table;
        }
    }

    throw new RuntimeException('Catalog variant table not found. Update ordersMergeVariantTable().');
}

function ordersMergeFixture(int $stock = 200, int $price = 1000, ?int $sale = null, string $status = 'published'): array
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
        $variant = DB::table(ordersMergeVariantTable())->where('sku', $sku)->first();
        expect($variant)->not->toBeNull();

        if ($stock > 0) {
            DB::table('product_variants')->where('id', $variant->id)->update(['stock_quantity' => $stock]);
        }

        return ['product_id' => (int) $product->id, 'variant_id' => (int) $variant->id, 'sku' => $sku];
    } finally {
        $test->withMiddleware();
    }
}

function ordersMergeBalance(int $variantId): array
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

    throw new RuntimeException('Inventory balance not found. Update ordersMergeBalance() to match Catalog.');
}

function ordersMergeLogin(User $user): void
{
    Auth::forgetGuards();
    test()->actingAs($user, 'customer')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
    ]);
}

function ordersMergeCustomer(): Customer
{
    $user = User::factory()->create(['role' => 'customer', 'is_active' => true]);

    return Customer::factory()->create(['user_id' => $user->id])->load('user');
}

function ordersMergeAmount(mixed $value, string $expected): void
{
    // Tests compare API money representations; production arithmetic must use minor units.
    expect(is_numeric($value))->toBeTrue();
    expect(number_format((float) $value, 2, '.', ''))->toBe($expected);
}

function ordersMergePayload(array $overrides = []): array
{
    return array_replace_recursive([
        'payment_method' => 'cod',
        'contact' => ['name' => 'Rahim Ahmed', 'phone' => '+8801712345678', 'email' => 'guest@example.test'],
        'delivery' => [
            'recipient_name' => 'Rahim Ahmed', 'phone' => '+8801712345678',
            'address_line1' => 'House 12, Road 3', 'city' => 'Dhaka',
            'district' => 'Dhaka', 'division' => 'Dhaka', 'country_code' => 'BD',
        ],
    ], $overrides);
}

function ordersMergeAdd(array $item, int $quantity = 2): void
{
    test()->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => $quantity])
        ->assertSuccessful();
}

function ordersMergeReviewed(array $payload): array
{
    $review = test()->postJson('/api/checkout/review', $payload)->assertOk();
    $token = $review->json('data.review_token');
    expect($token)->toBeString()->not->toBeEmpty();

    return [...$payload, 'review_token' => $token, 'idempotency_key' => 'test-'.Str::uuid()];
}

function ordersMergeNoOrderWrites(array $counts): void
{
    foreach ($counts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
}

function ordersMergeCounts(): array
{
    return collect(['orders', 'order_items', 'payments'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
}

/** Real login exercises the application's guest-cart association handoff. */
function ordersMergeRealLogin(Customer $customer): void
{
    $customer->user->forceFill(['password' => 'CartMergePassword@123'])->save();
    test()->postJson('/api/auth/customer/login', [
        'email' => $customer->user->email, 'password' => 'CartMergePassword@123',
    ])->assertOk();
}

it('requires a customer session before guest cart merge', function (): void {
    $item = ordersMergeFixture();
    ordersMergeAdd($item);
    $this->postJson('/api/cart/merge')->assertForbidden();
    $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.cart.items.0.quantity', 2);
});

it('moves this sessions guest cart after real customer login and makes retries harmless', function (): void {
    $customer = ordersMergeCustomer();
    $item = ordersMergeFixture();
    ordersMergeAdd($item, 3);
    $balance = ordersMergeBalance($item['variant_id']);
    $counts = ordersMergeCounts();
    ordersMergeRealLogin($customer);
    $this->postJson('/api/cart/merge')->assertOk()->assertJsonCount(1, 'data.cart.items')
        ->assertJsonPath('data.cart.items.0.variant_id', $item['variant_id'])
        ->assertJsonPath('data.cart.items.0.quantity', 3);
    $this->postJson('/api/cart/merge')->assertOk()->assertJsonCount(1, 'data.cart.items')
        ->assertJsonPath('data.cart.items.0.quantity', 3);
    expect(ordersMergeBalance($item['variant_id']))->toBe($balance);
    ordersMergeNoOrderWrites($counts);
});

it('combines duplicate variants with the customers existing cart', function (): void {
    $customer = ordersMergeCustomer();
    $item = ordersMergeFixture();
    ordersMergeRealLogin($customer);
    ordersMergeAdd($item, 4);
    $this->postJson('/api/auth/customer/logout')->assertSuccessful();
    Auth::forgetGuards();
    ordersMergeAdd($item, 3);
    ordersMergeRealLogin($customer);
    $response = $this->postJson('/api/cart/merge')->assertOk()->assertJsonCount(1, 'data.cart.items')
        ->assertJsonPath('data.cart.items.0.quantity', 7);
    ordersMergeAmount($response->json('data.cart.estimated_total'), '7000.00');
});

it('preserves distinct customer and guest variants during merge', function (): void {
    $customer = ordersMergeCustomer();
    $first = ordersMergeFixture();
    $second = ordersMergeFixture(price: 500);
    ordersMergeRealLogin($customer);
    ordersMergeAdd($first, 2);
    $this->postJson('/api/auth/customer/logout')->assertSuccessful();
    Auth::forgetGuards();
    ordersMergeAdd($second, 3);
    ordersMergeRealLogin($customer);
    $response = $this->postJson('/api/cart/merge')->assertOk()->assertJsonCount(2, 'data.cart.items');
    $lines = collect($response->json('data.cart.items'))->keyBy('variant_id');
    expect($lines[$first['variant_id']]['quantity'])->toBe(2);
    expect($lines[$second['variant_id']]['quantity'])->toBe(3);
    ordersMergeAmount($response->json('data.cart.estimated_total'), '3500.00');
});

it('rolls back the whole merge when duplicate quantities exceed the limit', function (): void {
    $customer = ordersMergeCustomer();
    $duplicate = ordersMergeFixture();
    $guestOnly = ordersMergeFixture();
    ordersMergeRealLogin($customer);
    ordersMergeAdd($duplicate, 90);
    $this->postJson('/api/auth/customer/logout')->assertSuccessful();
    Auth::forgetGuards();
    ordersMergeAdd($guestOnly, 2);
    ordersMergeAdd($duplicate, 20);
    ordersMergeRealLogin($customer);
    $this->postJson('/api/cart/merge')->assertUnprocessable();
    $this->getJson('/api/cart')->assertOk()->assertJsonCount(1, 'data.cart.items')
        ->assertJsonPath('data.cart.items.0.variant_id', $duplicate['variant_id'])
        ->assertJsonPath('data.cart.items.0.quantity', 90);
    // A retry cannot partially import the guest-only line either.
    $this->postJson('/api/cart/merge')->assertUnprocessable();
    $this->getJson('/api/cart')->assertOk()->assertJsonCount(1, 'data.cart.items');
});

it('does not merge an expired guest cart', function (): void {
    $customer = ordersMergeCustomer();
    $item = ordersMergeFixture();
    ordersMergeAdd($item);
    try {
        $this->travel(31)->days();
        ordersMergeRealLogin($customer);
        // The reference promises non-merging, without specifying a status for expiry.
        $result = $this->postJson('/api/cart/merge');
        expect($result->status())->toBeIn([200, 409, 422]);
        $this->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'data.cart.items');
    } finally {
        $this->travelBack();
    }
});

it('rejects caller selected guest carts and account identifiers', function (string $field, mixed $value): void {
    $customer = ordersMergeCustomer();
    $item = ordersMergeFixture();
    ordersMergeAdd($item);
    ordersMergeRealLogin($customer);
    $this->postJson('/api/cart/merge', [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
    // A rejected payload must not consume the legitimate association.
    $this->postJson('/api/cart/merge')->assertOk()->assertJsonCount(1, 'data.cart.items')
        ->assertJsonPath('data.cart.items.0.quantity', 2);
})->with([['cart_id', 999], ['guest_token', 'forged-token'], ['customer_id', 999]]);

it('does not turn a signed in staff account into a shopper through merge', function (): void {
    $staff = User::factory()->create(['role' => 'staff', 'permissions' => ['orders.process']]);
    Auth::forgetGuards();
    $this->actingAs($staff, 'staff')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($staff),
    ]);
    $this->postJson('/api/cart/merge')->assertForbidden();
});
