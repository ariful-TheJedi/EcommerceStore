<?php

declare(strict_types=1);

/**
 * Location: tests/Feature/Orders/CheckoutIdempotencyTest.php
 * Run: .\vendor\bin\pest tests/Feature/Orders/CheckoutIdempotencyTest.php
 * Standalone: reuses the fixture approach in the previously passing checkout suite.
 * Every Orders request runs with middleware enabled; only Catalog setup bypasses it.
 * Based on the supplied route reference; not executed without the Laravel project.
 * Concurrency is covered separately, not by sequential retry assertions in this file.
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

function ordersIdemVariantTable(): string
{
    foreach (['product_variants', 'variants'] as $table) {
        if (Schema::hasTable($table)) {
            return $table;
        }
    }

    throw new RuntimeException('Catalog variant table not found. Update ordersIdemVariantTable().');
}

function ordersIdemFixture(int $stock = 200, int $price = 1000, ?int $sale = null, string $status = 'published'): array
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
        $variant = DB::table(ordersIdemVariantTable())->where('sku', $sku)->first();
        expect($variant)->not->toBeNull();

        if ($stock > 0) {
            DB::table('product_variants')->where('id', $variant->id)->update(['stock_quantity' => $stock]);
        }

        return ['product_id' => (int) $product->id, 'variant_id' => (int) $variant->id, 'sku' => $sku];
    } finally {
        $test->withMiddleware();
    }
}

function ordersIdemBalance(int $variantId): array
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

    throw new RuntimeException('Inventory balance not found. Update ordersIdemBalance() to match Catalog.');
}

function ordersIdemLogin(User $user): void
{
    Auth::forgetGuards();
    test()->actingAs($user, 'customer')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
    ]);
}

function ordersIdemCustomer(): Customer
{
    $user = User::factory()->create(['role' => 'customer', 'is_active' => true]);

    return Customer::factory()->create(['user_id' => $user->id])->load('user');
}

function ordersIdemAmount(mixed $value, string $expected): void
{
    // Tests compare API money representations; production arithmetic must use minor units.
    expect(is_numeric($value))->toBeTrue();
    expect(number_format((float) $value, 2, '.', ''))->toBe($expected);
}

function ordersIdemPayload(array $overrides = []): array
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

function ordersIdemAdd(array $item, int $quantity = 2): void
{
    test()->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => $quantity])
        ->assertSuccessful();
}

function ordersIdemReviewed(array $payload): array
{
    $review = test()->postJson('/api/checkout/review', $payload)->assertOk();
    $token = $review->json('data.review_token');
    expect($token)->toBeString()->not->toBeEmpty();

    return [...$payload, 'review_token' => $token, 'idempotency_key' => 'test-'.Str::uuid()];
}

function ordersIdemNoOrderWrites(array $counts): void
{
    foreach ($counts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
}

function ordersIdemCounts(): array
{
    return collect(['orders', 'order_items', 'payments'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
}

function ordersIdemPlace(?Customer $customer = null, array $overrides = []): array
{
    if ($customer !== null) {
        ordersIdemLogin($customer->user);
    }
    $item = ordersIdemFixture();
    ordersIdemAdd($item);
    $payload = ordersIdemPayload($overrides);
    if ($customer !== null) {
        unset($payload['contact']);
    }
    $request = ordersIdemReviewed($payload);
    $response = test()->postJson('/api/checkout', $request)->assertCreated();

    return [
        'id' => $response->json('data.order.id'),
        'number' => $response->json('data.order.order_number'),
        'token' => $response->json('data.guest_access_token'),
        'order' => $response->json('data.order'), 'request' => $request, 'item' => $item,
    ];
}

function ordersIdemStaff(array $permissions = ['orders.view', 'orders.process']): User
{
    $staff = User::factory()->create(['role' => 'staff', 'is_active' => true, 'permissions' => $permissions]);
    Auth::forgetGuards();
    test()->actingAs($staff, 'staff')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($staff),
    ]);

    return $staff;
}

function ordersIdemAssertPrivate(mixed $data): void
{
    if (! is_array($data)) {
        return;
    }
    foreach ($data as $key => $value) {
        if (is_string($key)) {
            expect($key)->not->toBeIn([
                'password', 'remember_token', 'guest_token_hash', 'guest_access_token_hash',
                'checkout_hash', 'request_hash', 'provider_response', 'actor_id',
                'internal_notes', 'recorded_by', 'reconciled_by',
            ]);
        }
        ordersIdemAssertPrivate($value);
    }
}

it('replays guest checkout with the same order and recoverable secret without duplicate writes', function (): void {
    $placed = ordersIdemPlace();
    $counts = ordersIdemCounts();
    $balance = ordersIdemBalance($placed['item']['variant_id']);
    $history = DB::table('orders')->where('id', $placed['id'])->first();
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/checkout', $placed['request'])->assertOk()
            ->assertJsonPath('data.replayed', true)->assertJsonPath('data.order.id', $placed['id'])
            ->assertJsonPath('data.order.order_number', $placed['number'])
            ->assertJsonPath('data.guest_access_token', $placed['token']);
    }
    ordersIdemNoOrderWrites($counts);
    expect(ordersIdemBalance($placed['item']['variant_id']))->toBe($balance);
    expect((array) DB::table('orders')->where('id', $placed['id'])->first())->toBe((array) $history);
});

it('replays registered checkout after the cart has been converted', function (): void {
    $placed = ordersIdemPlace(ordersIdemCustomer());
    $counts = ordersIdemCounts();
    $balance = ordersIdemBalance($placed['item']['variant_id']);
    $this->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'data.cart.items');
    $this->postJson('/api/checkout', $placed['request'])->assertOk()
        ->assertJsonPath('data.replayed', true)->assertJsonPath('data.order.id', $placed['id'])
        ->assertJsonMissingPath('data.guest_access_token');
    ordersIdemNoOrderWrites($counts);
    expect(ordersIdemBalance($placed['item']['variant_id']))->toBe($balance);
});

it('rejects reuse with different delivery contact or notes and preserves the original purchase', function (string $field, string $value): void {
    $placed = ordersIdemPlace();
    $counts = ordersIdemCounts();
    $balance = ordersIdemBalance($placed['item']['variant_id']);
    $changed = $placed['request'];
    data_set($changed, $field, $value);
    $this->postJson('/api/checkout', $changed)->assertConflict();
    ordersIdemNoOrderWrites($counts);
    expect(ordersIdemBalance($placed['item']['variant_id']))->toBe($balance);
    $this->postJson('/api/checkout', $placed['request'])->assertOk()
        ->assertJsonPath('data.order.id', $placed['id'])->assertJsonPath('data.replayed', true);
})->with([
    ['delivery.address_line1', 'Changed house'], ['delivery.phone', '+8801812345678'],
    ['contact.name', 'Different person'], ['contact.email', 'different@example.test'],
    ['customer_note', 'Changed after placement'],
]);

it('scopes the same key to different registered customers', function (): void {
    $firstCustomer = ordersIdemCustomer();
    $secondCustomer = ordersIdemCustomer();
    $first = ordersIdemPlace($firstCustomer);
    ordersIdemLogin($secondCustomer->user);
    ordersIdemAdd(ordersIdemFixture());
    $payload = ordersIdemPayload();
    unset($payload['contact']);
    $request = ordersIdemReviewed($payload);
    $request['idempotency_key'] = $first['request']['idempotency_key'];
    $second = $this->postJson('/api/checkout', $request)->assertCreated()->assertJsonPath('data.replayed', false);
    expect($second->json('data.order.id'))->not->toBe($first['id']);
    $this->assertDatabaseHas('orders', ['id' => $second->json('data.order.id'), 'customer_id' => $secondCustomer->id]);
    ordersIdemLogin($firstCustomer->user);
    $this->postJson('/api/checkout', $first['request'])->assertOk()->assertJsonPath('data.order.id', $first['id']);
});

it('does not disclose a guest checkout or token to a different guest session using a stolen key', function (): void {
    $first = ordersIdemPlace();
    session()->invalidate();
    Auth::forgetGuards();
    $counts = ordersIdemCounts();
    $this->postJson('/api/checkout', $first['request'])->assertUnprocessable()
        ->assertJsonMissingPath('data.guest_access_token')->assertJsonMissingPath('data.order');
    ordersIdemNoOrderWrites($counts);
});

it('allows separate guest sessions to use the same key for separate legitimate purchases', function (): void {
    $first = ordersIdemPlace();
    session()->invalidate();
    Auth::forgetGuards();
    ordersIdemAdd(ordersIdemFixture());
    $request = ordersIdemReviewed(ordersIdemPayload());
    $request['idempotency_key'] = $first['request']['idempotency_key'];
    $second = $this->postJson('/api/checkout', $request)->assertCreated()->assertJsonPath('data.replayed', false);
    expect($second->json('data.order.id'))->not->toBe($first['id']);
    expect($second->json('data.guest_access_token'))->not->toBe($first['token']);
});

it('does not clear newly added cart items when an earlier purchase is replayed', function (): void {
    $customer = ordersIdemCustomer();
    $first = ordersIdemPlace($customer);
    $nextItem = ordersIdemFixture(price: 500);
    ordersIdemAdd($nextItem, 3);
    $counts = ordersIdemCounts();
    $this->postJson('/api/checkout', $first['request'])->assertOk()->assertJsonPath('data.order.id', $first['id']);
    ordersIdemNoOrderWrites($counts);
    $this->getJson('/api/cart')->assertOk()->assertJsonCount(1, 'data.cart.items')
        ->assertJsonPath('data.cart.items.0.variant_id', $nextItem['variant_id'])
        ->assertJsonPath('data.cart.items.0.quantity', 3);
});

it('permits retry after an earlier attempt failed validation without consuming its key', function (): void {
    $item = ordersIdemFixture();
    ordersIdemAdd($item);
    $valid = ordersIdemReviewed(ordersIdemPayload());
    $invalid = [...$valid, 'review_token' => 'invalid-review-token'];
    $counts = ordersIdemCounts();
    $this->postJson('/api/checkout', $invalid)->assertUnprocessable();
    ordersIdemNoOrderWrites($counts);
    $placed = $this->postJson('/api/checkout', $valid)->assertCreated();
    $this->postJson('/api/checkout', $valid)->assertOk()->assertJsonPath('data.order.id', $placed->json('data.order.id'));
});
