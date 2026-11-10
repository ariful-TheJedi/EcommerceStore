<?php

declare(strict_types=1);

/**
 * Location: tests/Feature/Orders/OrderDeliveryTest.php
 * Run: .\vendor\bin\pest tests/Feature/Orders/OrderDeliveryTest.php
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

function ordersDeliveryVariantTable(): string
{
    foreach (['product_variants', 'variants'] as $table) {
        if (Schema::hasTable($table)) {
            return $table;
        }
    }

    throw new RuntimeException('Catalog variant table not found. Update ordersDeliveryVariantTable().');
}

function ordersDeliveryFixture(int $stock = 200, int $price = 1000, ?int $sale = null, string $status = 'published'): array
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
        $variant = DB::table(ordersDeliveryVariantTable())->where('sku', $sku)->first();
        expect($variant)->not->toBeNull();

        if ($stock > 0) {
            DB::table('product_variants')->where('id', $variant->id)->update(['stock_quantity' => $stock]);
        }

        return ['product_id' => (int) $product->id, 'variant_id' => (int) $variant->id, 'sku' => $sku];
    } finally {
        $test->withMiddleware();
    }
}

function ordersDeliveryBalance(int $variantId): array
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

    throw new RuntimeException('Inventory balance not found. Update ordersDeliveryBalance() to match Catalog.');
}

function ordersDeliveryLogin(User $user): void
{
    Auth::forgetGuards();
    test()->actingAs($user, 'customer')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
    ]);
}

function ordersDeliveryCustomer(): Customer
{
    $user = User::factory()->create(['role' => 'customer', 'is_active' => true]);

    return Customer::factory()->create(['user_id' => $user->id])->load('user');
}

function ordersDeliveryAmount(mixed $value, string $expected): void
{
    // Tests compare API money representations; production arithmetic must use minor units.
    expect(is_numeric($value))->toBeTrue();
    expect(number_format((float) $value, 2, '.', ''))->toBe($expected);
}

function ordersDeliveryPayload(array $overrides = []): array
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

function ordersDeliveryAdd(array $item, int $quantity = 2): void
{
    test()->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => $quantity])
        ->assertSuccessful();
}

function ordersDeliveryReviewed(array $payload): array
{
    $review = test()->postJson('/api/checkout/review', $payload)->assertOk();
    $token = $review->json('data.review_token');
    expect($token)->toBeString()->not->toBeEmpty();

    return [...$payload, 'review_token' => $token, 'idempotency_key' => 'test-'.Str::uuid()];
}

function ordersDeliveryNoOrderWrites(array $counts): void
{
    foreach ($counts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
}

function ordersDeliveryCounts(): array
{
    return collect(['orders', 'order_items', 'payments'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
}

function ordersDeliveryPlace(?Customer $customer = null, array $overrides = []): array
{
    if ($customer !== null) {
        ordersDeliveryLogin($customer->user);
    }
    $item = ordersDeliveryFixture();
    ordersDeliveryAdd($item);
    $payload = ordersDeliveryPayload($overrides);
    if ($customer !== null) {
        unset($payload['contact']);
    }
    $request = ordersDeliveryReviewed($payload);
    $response = test()->postJson('/api/checkout', $request)->assertCreated();

    return [
        'id' => $response->json('data.order.id'),
        'number' => $response->json('data.order.order_number'),
        'token' => $response->json('data.guest_access_token'),
        'order' => $response->json('data.order'), 'request' => $request, 'item' => $item,
    ];
}

function ordersDeliveryStaff(array $permissions = ['orders.view', 'orders.process']): User
{
    $staff = User::factory()->create(['role' => 'staff', 'is_active' => true, 'permissions' => $permissions]);
    Auth::forgetGuards();
    test()->actingAs($staff, 'staff')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($staff),
    ]);

    return $staff;
}

function ordersDeliveryAssertPrivate(mixed $data): void
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
        ordersDeliveryAssertPrivate($value);
    }
}

function ordersDeliveryAdvance(array $placed, string $target): void
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

function ordersDeliveryDetails(array $placed): array
{
    return test()->getJson('/api/orders/'.$placed['id'])->assertOk()->json('data.order');
}

function ordersDeliveryShipping(array $placed): array
{
    $raw = DB::table('orders')->where('id', $placed['id'])->value('shipping_address');

    return is_array($raw) ? $raw : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

it('corrects delivery details before shipment with an audit while keeping charges and item snapshots unchanged', function (string $state): void {
    $placed = ordersDeliveryPlace();
    ordersDeliveryStaff();
    ordersDeliveryAdvance($placed, $state);
    $before = ordersDeliveryDetails($placed);
    $delivery = ordersDeliveryPayload()['delivery'];
    $delivery['recipient_name'] = 'Updated Recipient';
    $delivery['phone'] = '+8801812345678';
    $delivery['address_line1'] = 'House 99, Road 10';
    $delivery['delivery_instructions'] = 'Call before arrival';
    $response = $this->patchJson('/api/orders/'.$placed['id'].'/delivery-address', [
        'delivery' => $delivery, 'note' => 'AUDIT-DELIVERY-CORRECTION',
    ])->assertOk()->assertSee('AUDIT-DELIVERY-CORRECTION');
    $after = $response->json('data.order');
    expect($after['items'])->toBe($before['items']);
    expect($after['total_amount'])->toBe($before['total_amount']);
    expect($after['shipping_fee'])->toBe($before['shipping_fee']);
    expect(count($after['history']))->toBe(count($before['history']) + 1);
    $saved = ordersDeliveryShipping($placed);
    expect($saved['recipient_name'])->toBe('Updated Recipient');
    expect($saved['address_line1'])->toBe('House 99, Road 10');
    expect($saved['phone'])->toBe('+8801812345678');
})->with(['pending', 'confirmed', 'processing']);

it('rejects district changes that could alter the delivery charge', function (): void {
    $placed = ordersDeliveryPlace();
    ordersDeliveryStaff();
    $before = ordersDeliveryShipping($placed);
    $delivery = ordersDeliveryPayload(['delivery' => ['district' => 'Sylhet', 'city' => 'Sylhet', 'division' => 'Sylhet']])['delivery'];
    $this->patchJson('/api/orders/'.$placed['id'].'/delivery-address', [
        'delivery' => $delivery, 'note' => 'Different delivery district',
    ])->assertUnprocessable();
    expect(ordersDeliveryShipping($placed))->toBe($before);
});

it('rejects delivery address corrections after shipment delivery or cancellation', function (string $state): void {
    $placed = ordersDeliveryPlace();
    ordersDeliveryStaff();
    if ($state === 'cancelled') {
        $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'cancelled', 'reason' => 'Cancelled'])->assertOk();
    } else {
        ordersDeliveryAdvance($placed, $state);
    }
    $before = ordersDeliveryShipping($placed);
    $this->patchJson('/api/orders/'.$placed['id'].'/delivery-address', [
        'delivery' => ordersDeliveryPayload(['delivery' => ['address_line1' => 'Too late']])['delivery'], 'note' => 'Late correction',
    ])->assertConflict();
    expect(ordersDeliveryShipping($placed))->toBe($before);
})->with(['shipped', 'delivered', 'cancelled']);

it('requires a complete valid delivery address and correction note', function (): void {
    $placed = ordersDeliveryPlace();
    ordersDeliveryStaff();
    $url = '/api/orders/'.$placed['id'].'/delivery-address';
    $this->patchJson($url, ['delivery' => ['address_line1' => 'Incomplete'], 'note' => 'Incomplete address'])->assertUnprocessable();
    $this->patchJson($url, ['delivery' => ordersDeliveryPayload()['delivery']])->assertUnprocessable()->assertJsonValidationErrors('note');
    $this->patchJson($url, ['delivery' => ordersDeliveryPayload(['delivery' => ['phone' => '01712345678']])['delivery'], 'note' => 'Bad phone'])
        ->assertUnprocessable();
});

it('stores manual courier tracking and shipment and delivery timestamps', function (): void {
    $placed = ordersDeliveryPlace();
    ordersDeliveryStaff();
    $this->patchJson('/api/orders/'.$placed['id'].'/delivery', [
        'delivery_method' => 'standard', 'courier_name' => 'Pathao Courier', 'tracking_reference' => 'TRACK-ORDER-123',
    ])->assertOk()->assertSee('Pathao Courier')->assertSee('TRACK-ORDER-123');
    ordersDeliveryAdvance($placed, 'shipped');
    $shipped = ordersDeliveryDetails($placed);
    expect($shipped['shipped_at'])->not->toBeNull();
    $this->patchJson('/api/orders/'.$placed['id'].'/delivery', ['delivery_status' => 'in_transit', 'note' => 'Courier picked up'])->assertOk();
    $response = $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'delivered'])
        ->assertOk()->assertJsonPath('data.order.payment_status', 'unpaid');
    expect($response->json('data.order.delivered_at'))->not->toBeNull();
    expect($response->json('data.order.shipped_at'))->toBe($shipped['shipped_at']);
});

it('records failed delivery and a reattempt without losing failure history or restocking', function (): void {
    $placed = ordersDeliveryPlace();
    ordersDeliveryStaff();
    ordersDeliveryAdvance($placed, 'shipped');
    $balance = ordersDeliveryBalance($placed['item']['variant_id']);
    $this->patchJson('/api/orders/'.$placed['id'].'/delivery', [
        'delivery_status' => 'failed', 'note' => 'FAILURE-CUSTOMER-UNAVAILABLE',
    ])->assertOk()->assertJsonPath('data.order.status', 'shipped')->assertJsonPath('data.order.delivery_status', 'failed');
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'delivered'])->assertConflict();
    $this->patchJson('/api/orders/'.$placed['id'].'/delivery', [
        'delivery_status' => 'in_transit', 'note' => 'REATTEMPT-SCHEDULED',
    ])->assertOk()->assertSee('FAILURE-CUSTOMER-UNAVAILABLE')->assertSee('REATTEMPT-SCHEDULED');
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'delivered'])
        ->assertOk()->assertSee('FAILURE-CUSTOMER-UNAVAILABLE')->assertJsonPath('data.order.payment_status', 'unpaid');
    expect(ordersDeliveryBalance($placed['item']['variant_id']))->toBe($balance);
});

it('requires a shipped order for delivery status changes', function (string $state): void {
    $placed = ordersDeliveryPlace();
    ordersDeliveryStaff();
    ordersDeliveryAdvance($placed, $state);
    $this->patchJson('/api/orders/'.$placed['id'].'/delivery', ['delivery_status' => 'in_transit'])->assertConflict();
})->with(['pending', 'confirmed', 'processing']);

it('requires a nonblank note for failed delivery and rejects invalid courier inputs', function (): void {
    $placed = ordersDeliveryPlace();
    ordersDeliveryStaff();
    ordersDeliveryAdvance($placed, 'shipped');
    $url = '/api/orders/'.$placed['id'].'/delivery';
    foreach ([['delivery_status' => 'failed'], ['delivery_status' => 'failed', 'note' => '   '],
        ['delivery_status' => 'unknown'], ['delivery_method' => 'unsupported'],
        ['courier_name' => str_repeat('x', 101)], ['tracking_reference' => str_repeat('x', 151)],
        ['note' => str_repeat('x', 1001)]] as $payload) {
        $this->patchJson($url, $payload)->assertUnprocessable();
    }
});

it('rejects courier updates on cancelled and delivered orders', function (string $state): void {
    $placed = ordersDeliveryPlace();
    ordersDeliveryStaff();
    if ($state === 'cancelled') {
        $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'cancelled', 'reason' => 'Cancelled'])->assertOk();
    } else {
        ordersDeliveryAdvance($placed, $state);
    }
    $this->patchJson('/api/orders/'.$placed['id'].'/delivery', ['courier_name' => 'Too late'])->assertConflict();
})->with(['cancelled', 'delivered']);

it('requires process permission for both delivery administration routes', function (): void {
    $placed = ordersDeliveryPlace();
    ordersDeliveryStaff(['orders.view']);
    $this->patchJson('/api/orders/'.$placed['id'].'/delivery', ['courier_name' => 'Denied'])->assertForbidden();
    $this->patchJson('/api/orders/'.$placed['id'].'/delivery-address', [
        'delivery' => ordersDeliveryPayload()['delivery'], 'note' => 'Denied',
    ])->assertForbidden();
});
