<?php

declare(strict_types=1);

/**
 * Location: tests/Feature/Orders/OrderInventoryTest.php
 * Run: .\vendor\bin\pest tests/Feature/Orders/OrderInventoryTest.php
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

function ordersStockVariantTable(): string
{
    foreach (['product_variants', 'variants'] as $table) {
        if (Schema::hasTable($table)) {
            return $table;
        }
    }

    throw new RuntimeException('Catalog variant table not found. Update ordersStockVariantTable().');
}

function ordersStockFixture(int $stock = 200, int $price = 1000, ?int $sale = null, string $status = 'published'): array
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
        $variant = DB::table(ordersStockVariantTable())->where('sku', $sku)->first();
        expect($variant)->not->toBeNull();

        if ($stock > 0) {
            DB::table('product_variants')->where('id', $variant->id)->update(['stock_quantity' => $stock]);
        }

        return ['product_id' => (int) $product->id, 'variant_id' => (int) $variant->id, 'sku' => $sku];
    } finally {
        $test->withMiddleware();
    }
}

function ordersStockBalance(int $variantId): array
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

    throw new RuntimeException('Inventory balance not found. Update ordersStockBalance() to match Catalog.');
}

function ordersStockLogin(User $user): void
{
    Auth::forgetGuards();
    test()->actingAs($user, 'customer')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
    ]);
}

function ordersStockCustomer(): Customer
{
    $user = User::factory()->create(['role' => 'customer', 'is_active' => true]);

    return Customer::factory()->create(['user_id' => $user->id])->load('user');
}

function ordersStockAmount(mixed $value, string $expected): void
{
    // Tests compare API money representations; production arithmetic must use minor units.
    expect(is_numeric($value))->toBeTrue();
    expect(number_format((float) $value, 2, '.', ''))->toBe($expected);
}

function ordersStockPayload(array $overrides = []): array
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

function ordersStockAdd(array $item, int $quantity = 2): void
{
    test()->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => $quantity])
        ->assertSuccessful();
}

function ordersStockReviewed(array $payload): array
{
    $review = test()->postJson('/api/checkout/review', $payload)->assertOk();
    $token = $review->json('data.review_token');
    expect($token)->toBeString()->not->toBeEmpty();

    return [...$payload, 'review_token' => $token, 'idempotency_key' => 'test-'.Str::uuid()];
}

function ordersStockNoOrderWrites(array $counts): void
{
    foreach ($counts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
}

function ordersStockCounts(): array
{
    return collect(['orders', 'order_items', 'payments'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
}

function ordersStockPlace(?Customer $customer = null, array $overrides = []): array
{
    if ($customer !== null) {
        ordersStockLogin($customer->user);
    }
    $item = ordersStockFixture();
    ordersStockAdd($item);
    $payload = ordersStockPayload($overrides);
    if ($customer !== null) {
        unset($payload['contact']);
    }
    $request = ordersStockReviewed($payload);
    $response = test()->postJson('/api/checkout', $request)->assertCreated();

    return [
        'id' => $response->json('data.order.id'),
        'number' => $response->json('data.order.order_number'),
        'token' => $response->json('data.guest_access_token'),
        'order' => $response->json('data.order'), 'request' => $request, 'item' => $item,
    ];
}

function ordersStockStaff(array $permissions = ['orders.view', 'orders.process']): User
{
    $staff = User::factory()->create(['role' => 'staff', 'is_active' => true, 'permissions' => $permissions]);
    Auth::forgetGuards();
    test()->actingAs($staff, 'staff')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($staff),
    ]);

    return $staff;
}

function ordersStockAssertPrivate(mixed $data): void
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
        ordersStockAssertPrivate($value);
    }
}

function ordersStockAdvance(array $placed, string $target): void
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

function ordersStockDetails(array $placed): array
{
    return test()->getJson('/api/orders/'.$placed['id'])->assertOk()->json('data.order');
}

function ordersStockShipping(array $placed): array
{
    $raw = DB::table('orders')->where('id', $placed['id'])->value('shipping_address');

    return is_array($raw) ? $raw : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

it('reserves stock at placement and leaves on hand unchanged until shipment', function (): void {
    $item = ordersStockFixture(stock: 10);
    $before = ordersStockBalance($item['variant_id']);
    ordersStockAdd($item, 3);
    $response = $this->postJson('/api/checkout', ordersStockReviewed(ordersStockPayload()))->assertCreated();
    $placed = ['id' => $response->json('data.order.id')];
    $reserved = ['on_hand' => $before['on_hand'], 'reserved' => $before['reserved'] + 3];
    expect(ordersStockBalance($item['variant_id']))->toBe($reserved);
    ordersStockStaff();
    foreach (['confirmed', 'processing'] as $state) {
        $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => $state])->assertOk();
        expect(ordersStockBalance($item['variant_id']))->toBe($reserved);
    }
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'shipped'])->assertOk();
    expect(ordersStockBalance($item['variant_id']))->toBe([
        'on_hand' => $before['on_hand'] - 3, 'reserved' => $before['reserved'],
    ]);
});

it('releases reservations once on cancellation without increasing on hand', function (string $state): void {
    $placed = ordersStockPlace();
    $reserved = ordersStockBalance($placed['item']['variant_id']);
    ordersStockStaff();
    ordersStockAdvance($placed, $state);
    $payload = ['status' => 'cancelled', 'reason' => 'Inventory cancellation test'];
    for ($i = 0; $i < 3; $i++) {
        $this->patchJson('/api/orders/'.$placed['id'].'/status', $payload)->assertOk();
        expect(ordersStockBalance($placed['item']['variant_id']))->toBe([
            'on_hand' => $reserved['on_hand'], 'reserved' => $reserved['reserved'] - 2,
        ]);
    }
    $payment = DB::table('payments')->where('order_id', $placed['id'])->sole();
    expect($payment->paid_at)->toBeNull();
})->with(['pending', 'confirmed', 'processing']);

it('deducts shipped stock once and delivery adds no further stock movement', function (): void {
    $placed = ordersStockPlace();
    ordersStockStaff();
    ordersStockAdvance($placed, 'shipped');
    $balance = ordersStockBalance($placed['item']['variant_id']);
    for ($i = 0; $i < 3; $i++) {
        $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'shipped'])->assertOk();
    }
    expect(ordersStockBalance($placed['item']['variant_id']))->toBe($balance);
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'delivered'])->assertOk();
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'delivered'])->assertOk();
    expect(ordersStockBalance($placed['item']['variant_id']))->toBe($balance);
});

it('does not restock shipped goods after rejected cancellation or failed delivery', function (): void {
    $placed = ordersStockPlace();
    ordersStockStaff();
    ordersStockAdvance($placed, 'shipped');
    $balance = ordersStockBalance($placed['item']['variant_id']);
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'cancelled', 'reason' => 'Already shipped'])
        ->assertConflict();
    $this->patchJson('/api/orders/'.$placed['id'].'/delivery', ['delivery_status' => 'failed', 'note' => 'Customer unavailable'])
        ->assertOk()->assertJsonPath('data.order.status', 'shipped');
    expect(ordersStockBalance($placed['item']['variant_id']))->toBe($balance);
});

it('keeps stock changes isolated between different orders for the same variant', function (): void {
    $customer = ordersStockCustomer();
    ordersStockLogin($customer->user);
    $item = ordersStockFixture(stock: 10);
    $initial = ordersStockBalance($item['variant_id']);
    $payload = ordersStockPayload();
    unset($payload['contact']);
    ordersStockAdd($item, 2);
    $first = $this->postJson('/api/checkout', ordersStockReviewed($payload))->assertCreated()->json('data.order.id');
    ordersStockAdd($item, 3);
    $second = $this->postJson('/api/checkout', ordersStockReviewed($payload))->assertCreated()->json('data.order.id');
    expect(ordersStockBalance($item['variant_id']))->toBe(['on_hand' => $initial['on_hand'], 'reserved' => $initial['reserved'] + 5]);
    ordersStockStaff();
    $this->patchJson('/api/orders/'.$first.'/status', ['status' => 'cancelled', 'reason' => 'First only'])->assertOk();
    expect(ordersStockBalance($item['variant_id']))->toBe(['on_hand' => $initial['on_hand'], 'reserved' => $initial['reserved'] + 3]);
    ordersStockAdvance(['id' => $second], 'shipped');
    expect(ordersStockBalance($item['variant_id']))->toBe(['on_hand' => $initial['on_hand'] - 3, 'reserved' => $initial['reserved']]);
});

it('reserves and releases all variants in a multi line order together', function (): void {
    $first = ordersStockFixture(stock: 10);
    $second = ordersStockFixture(stock: 20);
    $beforeFirst = ordersStockBalance($first['variant_id']);
    $beforeSecond = ordersStockBalance($second['variant_id']);
    ordersStockAdd($first, 2);
    ordersStockAdd($second, 4);
    $id = $this->postJson('/api/checkout', ordersStockReviewed(ordersStockPayload()))->assertCreated()->json('data.order.id');
    expect(ordersStockBalance($first['variant_id']))->toBe(['on_hand' => $beforeFirst['on_hand'], 'reserved' => $beforeFirst['reserved'] + 2]);
    expect(ordersStockBalance($second['variant_id']))->toBe(['on_hand' => $beforeSecond['on_hand'], 'reserved' => $beforeSecond['reserved'] + 4]);
    ordersStockStaff();
    $this->patchJson('/api/orders/'.$id.'/status', ['status' => 'cancelled', 'reason' => 'Cancel all lines'])->assertOk();
    expect(ordersStockBalance($first['variant_id']))->toBe($beforeFirst);
    expect(ordersStockBalance($second['variant_id']))->toBe($beforeSecond);
});

it('keeps inventory unchanged when a status transition is invalid or unauthorized', function (): void {
    $placed = ordersStockPlace();
    $balance = ordersStockBalance($placed['item']['variant_id']);
    ordersStockStaff(['orders.view']);
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'confirmed'])->assertForbidden();
    ordersStockStaff();
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'shipped'])->assertConflict();
    expect(ordersStockBalance($placed['item']['variant_id']))->toBe($balance);
});

it('does not expire placed COD orders when their original carts expire', function (): void {
    $placed = ordersStockPlace();
    $balance = ordersStockBalance($placed['item']['variant_id']);
    try {
        $this->travel(31)->days();
        $this->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'data.cart.items');
        $this->withHeader('X-Guest-Order-Token', $placed['token'])->getJson('/api/guest/orders/'.$placed['number'])
            ->assertOk()->assertJsonPath('data.order.status', 'pending');
        expect(ordersStockBalance($placed['item']['variant_id']))->toBe($balance);
    } finally {
        $this->travelBack();
    }
});
