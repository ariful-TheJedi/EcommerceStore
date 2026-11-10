<?php

declare(strict_types=1);

/**
 * Location: tests/Feature/Orders/OrderSecurityTest.php
 * Run: .\vendor\bin\pest tests/Feature/Orders/OrderSecurityTest.php
 * Standalone file; uses the previous Catalog/checkout fixture pattern.
 * Middleware remains enabled on Orders routes. Not executed here.
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

function ordersSecureVariantTable(): string
{
    foreach (['product_variants', 'variants'] as $table) {
        if (Schema::hasTable($table)) {
            return $table;
        }
    }

    throw new RuntimeException('Catalog variant table not found. Update ordersSecureVariantTable().');
}

function ordersSecureFixture(int $stock = 200, int $price = 1000, ?int $sale = null, string $status = 'published'): array
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
        $variant = DB::table(ordersSecureVariantTable())->where('sku', $sku)->first();
        expect($variant)->not->toBeNull();

        if ($stock > 0) {
            DB::table('product_variants')->where('id', $variant->id)->update(['stock_quantity' => $stock]);
        }

        return ['product_id' => (int) $product->id, 'variant_id' => (int) $variant->id, 'sku' => $sku];
    } finally {
        $test->withMiddleware();
    }
}

function ordersSecureBalance(int $variantId): array
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

    throw new RuntimeException('Inventory balance not found. Update ordersSecureBalance() to match Catalog.');
}

function ordersSecureLogin(User $user): void
{
    Auth::forgetGuards();
    test()->actingAs($user, 'customer')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
    ]);
}

function ordersSecureCustomer(): Customer
{
    $user = User::factory()->create(['role' => 'customer', 'is_active' => true]);

    return Customer::factory()->create(['user_id' => $user->id])->load('user');
}

function ordersSecureAmount(mixed $value, string $expected): void
{
    // Tests compare API money representations; production arithmetic must use minor units.
    expect(is_numeric($value))->toBeTrue();
    expect(number_format((float) $value, 2, '.', ''))->toBe($expected);
}

function ordersSecurePayload(array $overrides = []): array
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

function ordersSecureAdd(array $item, int $quantity = 2): void
{
    test()->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => $quantity])
        ->assertSuccessful();
}

function ordersSecureReviewed(array $payload): array
{
    $review = test()->postJson('/api/checkout/review', $payload)->assertOk();
    $token = $review->json('data.review_token');
    expect($token)->toBeString()->not->toBeEmpty();

    return [...$payload, 'review_token' => $token, 'idempotency_key' => 'test-'.Str::uuid()];
}

function ordersSecureNoOrderWrites(array $counts): void
{
    foreach ($counts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
}

function ordersSecureCounts(): array
{
    return collect(['orders', 'order_items', 'payments'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
}

function ordersSecurePlace(?Customer $customer = null, array $overrides = []): array
{
    if ($customer !== null) {
        ordersSecureLogin($customer->user);
    }
    $item = ordersSecureFixture();
    ordersSecureAdd($item);
    $payload = ordersSecurePayload($overrides);
    if ($customer !== null) {
        unset($payload['contact']);
    }
    $request = ordersSecureReviewed($payload);
    $response = test()->postJson('/api/checkout', $request)->assertCreated();

    return [
        'id' => $response->json('data.order.id'),
        'number' => $response->json('data.order.order_number'),
        'token' => $response->json('data.guest_access_token'),
        'order' => $response->json('data.order'), 'request' => $request, 'item' => $item,
    ];
}

function ordersSecureStaff(array $permissions = ['orders.view', 'orders.process']): User
{
    $staff = User::factory()->create(['role' => 'staff', 'is_active' => true, 'permissions' => $permissions]);
    Auth::forgetGuards();
    test()->actingAs($staff, 'staff')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($staff),
    ]);

    return $staff;
}

function ordersSecureAssertPrivate(mixed $data): void
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
        ordersSecureAssertPrivate($value);
    }
}

function ordersSecureAdvance(array $placed, string $target): void
{
    if ($target === 'pending') {
        return;
    }
    foreach (['confirmed', 'processing', 'shipped', 'delivered'] as $status) {
        test()->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => $status])
            ->assertOk()->assertJsonPath('data.order.status', $status);
        if ($status === $target) {
            return;
        }
    }
    throw new InvalidArgumentException('Unknown target status: '.$target);
}

function ordersSecureDetails(array $placed): array
{
    return test()->getJson('/api/orders/'.$placed['id'])->assertOk()->json('data.order');
}

function ordersSecureShipping(array $placed): array
{
    $raw = DB::table('orders')->where('id', $placed['id'])->value('shipping_address');

    return is_array($raw) ? $raw : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    // Keep throttle counters local to this test; do not inherit previous suite runs.
    config(['cache.default' => 'array', 'cache.limiter' => 'array']);
});

it('blocks shopper routes for a staff session instead of creating a guest cart', function (): void {
    ordersSecureStaff();
    $counts = ordersSecureCounts();
    foreach (['/api/cart', '/api/customer/orders'] as $url) {
        $this->getJson($url)->assertStatus($url === '/api/cart' ? 403 : 401);
    }
    $this->postJson('/api/checkout/review', ordersSecurePayload())->assertForbidden();
    $this->postJson('/api/checkout', ordersSecurePayload())->assertForbidden();
    ordersSecureNoOrderWrites($counts);
});

it('rejects a blocked customers existing shopping session without silently becoming a guest', function (): void {
    $customer = ordersSecureCustomer();
    ordersSecureLogin($customer->user);
    $item = ordersSecureFixture();
    ordersSecureAdd($item);
    $payload = ordersSecurePayload();
    unset($payload['contact']);
    $checkout = ordersSecureReviewed($payload);
    $counts = ordersSecureCounts();
    // Keep the original fingerprint: this simulates an account disabled after login.
    $customer->user->forceFill(['is_active' => false])->save();
    $this->getJson('/api/cart')->assertUnauthorized();
    ordersSecureLogin($customer->user);
    $this->postJson('/api/checkout/review', $payload)->assertUnauthorized();
    ordersSecureLogin($customer->user);
    $this->postJson('/api/checkout', $checkout)->assertUnauthorized();
    ordersSecureNoOrderWrites($counts);
});

it('rejects a stale customer session fingerprint', function (): void {
    $customer = ordersSecureCustomer();
    ordersSecureLogin($customer->user);
    $this->withSession(['auth_fingerprint' => 'invalid-stale-fingerprint']);
    $this->getJson('/api/cart')->assertUnauthorized();
    $this->getJson('/api/customer/orders')->assertUnauthorized();
});

it('requires csrf on checkout review placement and guest cancellation', function (): void {
    $placed = ordersSecurePlace();
    $counts = ordersSecureCounts();
    $environment = $this->app->environment();
    $this->app->instance('env', 'local');
    try {
        $this->postJson('/api/checkout/review', ordersSecurePayload())->assertStatus(419);
        $this->postJson('/api/checkout', $placed['request'])->assertStatus(419);
        $this->withHeader('X-Guest-Order-Token', $placed['token'])
            ->postJson('/api/guest/orders/'.$placed['number'].'/cancel', ['reason' => 'Forged request'])->assertStatus(419);
    } finally {
        $this->app->instance('env', $environment);
    }
    ordersSecureNoOrderWrites($counts);
    $this->assertDatabaseHas('orders', ['id' => $placed['id'], 'status' => 'pending']);
});

it('requires csrf on staff status notes and both delivery writes', function (): void {
    $placed = ordersSecurePlace();
    ordersSecureStaff();
    $environment = $this->app->environment();
    $this->app->instance('env', 'local');
    try {
        $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'confirmed'])->assertStatus(419);
        $this->postJson('/api/orders/'.$placed['id'].'/notes', ['note' => 'Forged'])->assertStatus(419);
        $this->patchJson('/api/orders/'.$placed['id'].'/delivery', ['courier_name' => 'Forged'])->assertStatus(419);
        $this->patchJson('/api/orders/'.$placed['id'].'/delivery-address', [
            'delivery' => ordersSecurePayload()['delivery'], 'note' => 'Forged',
        ])->assertStatus(419);
    } finally {
        $this->app->instance('env', $environment);
    }
    $this->assertDatabaseHas('orders', ['id' => $placed['id'], 'status' => 'pending']);
});

it('rejects client supplied order ownership prices discounts taxes and fulfillment authority', function (string $field, mixed $value): void {
    ordersSecureAdd(ordersSecureFixture());
    $request = ordersSecureReviewed(ordersSecurePayload());
    $counts = ordersSecureCounts();
    $this->postJson('/api/checkout', [...$request, $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
    ordersSecureNoOrderWrites($counts);
    $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.cart.items.0.quantity', 2);
})->with([
    ['customer_id', 999], ['user_id', 999], ['subtotal', 1], ['total_amount', 1],
    ['shipping_fee', 0], ['tax_amount', 0], ['discount_amount', 999],
    ['status', 'delivered'], ['payment_status', 'paid'], ['recorded_by', 999],
]);

it('does not expose guest tokens hashes or payment provider data in order reads', function (): void {
    $placed = ordersSecurePlace();
    DB::table('payments')->where('order_id', $placed['id'])->update(['provider_response' => '{"secret":"DO-NOT-EXPOSE-PROVIDER"}']);
    $guest = $this->withHeader('X-Guest-Order-Token', $placed['token'])->getJson('/api/guest/orders/'.$placed['number'])
        ->assertOk()->assertDontSee('DO-NOT-EXPOSE-PROVIDER')->assertDontSee($placed['token']);
    ordersSecureAssertPrivate($guest->json());
    ordersSecureStaff();
    $staff = $this->getJson('/api/orders/'.$placed['id'])->assertOk()
        ->assertDontSee('DO-NOT-EXPOSE-PROVIDER')->assertDontSee($placed['token']);
    foreach (['guest_access_token', 'guest_token_hash', 'guest_access_token_hash', 'checkout_hash', 'provider_response'] as $field) {
        $staff->assertJsonMissingPath('data.order.'.$field);
    }
});

it('rate limits guest order token guessing to thirty requests per minute per IP', function (): void {
    $placed = ordersSecurePlace();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.201']);
    for ($i = 0; $i < 30; $i++) {
        $this->withHeader('X-Guest-Order-Token', 'wrong-'.Str::random(20))
            ->getJson('/api/guest/orders/'.$placed['number'])->assertNotFound();
    }
    $this->withHeader('X-Guest-Order-Token', 'wrong-again')->getJson('/api/guest/orders/'.$placed['number'])->assertStatus(429);
});

it('rate limits checkout attempts without allowing duplicate order creation', function (): void {
    // Invalid review requests exercise middleware without creating purchases.
    $this->getJson('/api/cart')->assertOk();
    $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.202']);
    $counts = ordersSecureCounts();
    for ($i = 0; $i < 30; $i++) {
        $this->postJson('/api/checkout', [])->assertUnprocessable();
    }
    $this->postJson('/api/checkout', [])->assertStatus(429);
    ordersSecureNoOrderWrites($counts);
});

it('treats SQL metacharacters in staff search as data and never broadens ownership access', function (): void {
    $placed = ordersSecurePlace();
    ordersSecureStaff(['orders.view']);
    $before = ordersSecureCounts();
    $this->getJson('/api/orders?'.http_build_query(['search' => "' OR 1=1 --"]))
        ->assertOk()->assertJsonCount(0, 'data.orders');
    ordersSecureNoOrderWrites($before);
    $this->getJson('/api/orders/'.$placed['id'])->assertOk();
});
