<?php

declare(strict_types=1);

/**
 * Location: tests/Feature/Orders/OrderStatusTest.php
 * Run: .\vendor\bin\pest tests/Feature/Orders/OrderStatusTest.php
 * Standalone Pest file using the earlier checkout and authentication fixture pattern.
 * Orders middleware stays enabled; only Catalog fixture creation bypasses it.
 * Contracts follow the supplied COD-only API reference. Not executed here.
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

function ordersStatusVariantTable(): string
{
    foreach (['product_variants', 'variants'] as $table) {
        if (Schema::hasTable($table)) {
            return $table;
        }
    }

    throw new RuntimeException('Catalog variant table not found. Update ordersStatusVariantTable().');
}

function ordersStatusFixture(int $stock = 200, int $price = 1000, ?int $sale = null, string $status = 'published'): array
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
        $variant = DB::table(ordersStatusVariantTable())->where('sku', $sku)->first();
        expect($variant)->not->toBeNull();

        if ($stock > 0) {
            DB::table('product_variants')->where('id', $variant->id)->update(['stock_quantity' => $stock]);
        }

        return ['product_id' => (int) $product->id, 'variant_id' => (int) $variant->id, 'sku' => $sku];
    } finally {
        $test->withMiddleware();
    }
}

function ordersStatusBalance(int $variantId): array
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

    throw new RuntimeException('Inventory balance not found. Update ordersStatusBalance() to match Catalog.');
}

function ordersStatusLogin(User $user): void
{
    Auth::forgetGuards();
    test()->actingAs($user, 'customer')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
    ]);
}

function ordersStatusCustomer(): Customer
{
    $user = User::factory()->create(['role' => 'customer', 'is_active' => true]);

    return Customer::factory()->create(['user_id' => $user->id])->load('user');
}

function ordersStatusAmount(mixed $value, string $expected): void
{
    // Tests compare API money representations; production arithmetic must use minor units.
    expect(is_numeric($value))->toBeTrue();
    expect(number_format((float) $value, 2, '.', ''))->toBe($expected);
}

function ordersStatusPayload(array $overrides = []): array
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

function ordersStatusAdd(array $item, int $quantity = 2): void
{
    test()->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => $quantity])
        ->assertSuccessful();
}

function ordersStatusReviewed(array $payload): array
{
    $review = test()->postJson('/api/checkout/review', $payload)->assertOk();
    $token = $review->json('data.review_token');
    expect($token)->toBeString()->not->toBeEmpty();

    return [...$payload, 'review_token' => $token, 'idempotency_key' => 'test-'.Str::uuid()];
}

function ordersStatusNoOrderWrites(array $counts): void
{
    foreach ($counts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
}

function ordersStatusCounts(): array
{
    return collect(['orders', 'order_items', 'payments'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
}

function ordersStatusPlace(?Customer $customer = null, array $overrides = []): array
{
    if ($customer !== null) {
        ordersStatusLogin($customer->user);
    }
    $item = ordersStatusFixture();
    ordersStatusAdd($item);
    $payload = ordersStatusPayload($overrides);
    if ($customer !== null) {
        unset($payload['contact']);
    }
    $request = ordersStatusReviewed($payload);
    $response = test()->postJson('/api/checkout', $request)->assertCreated();

    return [
        'id' => $response->json('data.order.id'),
        'number' => $response->json('data.order.order_number'),
        'token' => $response->json('data.guest_access_token'),
        'order' => $response->json('data.order'), 'request' => $request, 'item' => $item,
    ];
}

function ordersStatusStaff(array $permissions = ['orders.view', 'orders.process']): User
{
    $staff = User::factory()->create(['role' => 'staff', 'is_active' => true, 'permissions' => $permissions]);
    Auth::forgetGuards();
    test()->actingAs($staff, 'staff')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($staff),
    ]);

    return $staff;
}

function ordersStatusAssertPrivate(mixed $data): void
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
        ordersStatusAssertPrivate($value);
    }
}

function ordersStatusAdvance(array $placed, string $target): void
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

function ordersStatusDetails(array $placed): array
{
    return test()->getJson('/api/orders/'.$placed['id'])->assertOk()->json('data.order');
}

function ordersStatusShipping(array $placed): array
{
    $raw = DB::table('orders')->where('id', $placed['id'])->value('shipping_address');

    return is_array($raw) ? $raw : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

it('follows the complete fulfillment lifecycle and audits each transition without marking COD paid', function (): void {
    $placed = ordersStatusPlace();
    ordersStatusStaff();
    $previous = ordersStatusDetails($placed);
    expect($previous['history'])->toBeArray()->not->toBeEmpty();
    foreach (['confirmed', 'processing', 'shipped', 'delivered'] as $state) {
        $response = $this->patchJson('/api/orders/'.$placed['id'].'/status', [
            'status' => $state, 'note' => 'AUDIT-TRANSITION-'.$state,
        ])->assertOk()->assertJsonPath('data.order.status', $state)
            ->assertJsonPath('data.order.payment_status', 'unpaid')->assertSee('AUDIT-TRANSITION-'.$state);
        $current = $response->json('data.order');
        expect(count($current['history']))->toBe(count($previous['history']) + 1);
        $previous = $current;
    }
    $detail = $this->getJson('/api/orders/'.$placed['id'])->assertOk();
    foreach (['confirmed', 'processing', 'shipped', 'delivered'] as $state) {
        $detail->assertSee('AUDIT-TRANSITION-'.$state);
    }
    $payment = DB::table('payments')->where('order_id', $placed['id'])->sole();
    expect($payment->status)->toBe('pending');
    expect($payment->paid_at)->toBeNull();
});

it('rejects skipped backward and terminal state transitions without altering history', function (string $from, string $to): void {
    $placed = ordersStatusPlace();
    ordersStatusStaff();
    ordersStatusAdvance($placed, $from);
    $before = ordersStatusDetails($placed);
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => $to])->assertConflict();
    $after = ordersStatusDetails($placed);
    expect($after['status'])->toBe($from);
    expect($after['history'])->toBe($before['history']);
})->with([
    ['pending', 'processing'], ['pending', 'shipped'], ['pending', 'delivered'],
    ['confirmed', 'pending'], ['confirmed', 'shipped'], ['processing', 'confirmed'],
    ['shipped', 'processing'], ['delivered', 'shipped'],
]);

it('treats repeated requests for the current state as harmless retries', function (string $state): void {
    $placed = ordersStatusPlace();
    ordersStatusStaff();
    ordersStatusAdvance($placed, $state);
    $before = ordersStatusDetails($placed);
    $balance = ordersStatusBalance($placed['item']['variant_id']);
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => $state])->assertOk();
    $after = ordersStatusDetails($placed);
    expect($after['history'])->toBe($before['history']);
    expect(ordersStatusBalance($placed['item']['variant_id']))->toBe($balance);
})->with(['pending', 'confirmed', 'processing', 'shipped', 'delivered']);

it('allows staff cancellation in each permitted early state and records its reason', function (string $state): void {
    $placed = ordersStatusPlace();
    ordersStatusStaff();
    ordersStatusAdvance($placed, $state);
    $response = $this->patchJson('/api/orders/'.$placed['id'].'/status', [
        'status' => 'cancelled', 'reason' => 'Customer requested cancellation',
    ])->assertOk()->assertJsonPath('data.order.status', 'cancelled')->assertSee('Customer requested cancellation');
    $history = $response->json('data.order.history');
    $this->patchJson('/api/orders/'.$placed['id'].'/status', [
        'status' => 'cancelled', 'reason' => 'Customer requested cancellation',
    ])->assertOk()->assertJsonPath('data.order.history', $history);
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'confirmed'])->assertConflict();
})->with(['pending', 'confirmed', 'processing']);

it('refuses staff cancellation after shipment or delivery', function (string $state): void {
    $placed = ordersStatusPlace();
    ordersStatusStaff();
    ordersStatusAdvance($placed, $state);
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'cancelled', 'reason' => 'Too late'])
        ->assertConflict();
    $this->assertDatabaseHas('orders', ['id' => $placed['id'], 'status' => $state]);
})->with(['shipped', 'delivered']);

it('requires a nonblank cancellation reason and validates status fields', function (): void {
    $placed = ordersStatusPlace();
    ordersStatusStaff();
    foreach ([[], ['status' => 'not-a-status'], ['status' => 'cancelled'],
        ['status' => 'cancelled', 'reason' => '   '], ['status' => 'cancelled', 'reason' => str_repeat('x', 1001)],
        ['status' => 'confirmed', 'note' => str_repeat('x', 1001)]] as $payload) {
        $this->patchJson('/api/orders/'.$placed['id'].'/status', $payload)->assertUnprocessable();
    }
    $this->assertDatabaseHas('orders', ['id' => $placed['id'], 'status' => 'pending']);
});

it('allows customers to cancel only their own pending or confirmed orders', function (string $state): void {
    $customer = ordersStatusCustomer();
    $placed = ordersStatusPlace($customer);
    ordersStatusStaff();
    ordersStatusAdvance($placed, $state);
    ordersStatusLogin($customer->user);
    $this->postJson('/api/customer/orders/'.$placed['id'].'/cancel', ['reason' => 'Changed my mind'])
        ->assertOk()->assertJsonPath('data.order.status', 'cancelled');
})->with(['pending', 'confirmed']);

it('rejects customer cancellation once processing has begun', function (string $state): void {
    $customer = ordersStatusCustomer();
    $placed = ordersStatusPlace($customer);
    ordersStatusStaff();
    ordersStatusAdvance($placed, $state);
    ordersStatusLogin($customer->user);
    $this->postJson('/api/customer/orders/'.$placed['id'].'/cancel', ['reason' => 'Too late'])->assertConflict();
    $this->assertDatabaseHas('orders', ['id' => $placed['id'], 'status' => $state]);
})->with(['processing', 'shipped', 'delivered']);

it('applies the same early cancellation rules to guest secret access', function (string $state, int $expected): void {
    $placed = ordersStatusPlace();
    ordersStatusStaff();
    ordersStatusAdvance($placed, $state);
    $response = $this->withHeader('X-Guest-Order-Token', $placed['token'])
        ->postJson('/api/guest/orders/'.$placed['number'].'/cancel', ['reason' => 'Guest cancellation']);
    $response->assertStatus($expected);
    $this->assertDatabaseHas('orders', ['id' => $placed['id'], 'status' => $expected === 200 ? 'cancelled' : $state]);
})->with([['pending', 200], ['confirmed', 200], ['processing', 409], ['shipped', 409]]);

it('requires process permission and rejects caller selected actors or payment status', function (): void {
    $placed = ordersStatusPlace();
    ordersStatusStaff(['orders.view']);
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'confirmed'])->assertForbidden();
    ordersStatusStaff();
    foreach (['actor_id' => 999, 'payment_status' => 'paid'] as $field => $value) {
        $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'confirmed', $field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    $this->assertDatabaseHas('orders', ['id' => $placed['id'], 'status' => 'pending']);
});
