<?php

declare(strict_types=1);

/**
 * Location: tests/Feature/Orders/OrdersAutoFlowTest.php
 * Run: .\vendor\bin\pest tests/Feature/Orders/OrdersAutoFlowTest.php
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

function ordersFlowVariantTable(): string
{
    foreach (['product_variants', 'variants'] as $table) {
        if (Schema::hasTable($table)) {
            return $table;
        }
    }

    throw new RuntimeException('Catalog variant table not found. Update ordersFlowVariantTable().');
}

function ordersFlowFixture(int $stock = 200, int $price = 1000, ?int $sale = null, string $status = 'published'): array
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
        $variant = DB::table(ordersFlowVariantTable())->where('sku', $sku)->first();
        expect($variant)->not->toBeNull();

        if ($stock > 0) {
            DB::table('product_variants')->where('id', $variant->id)->update(['stock_quantity' => $stock]);
        }

        return ['product_id' => (int) $product->id, 'variant_id' => (int) $variant->id, 'sku' => $sku];
    } finally {
        $test->withMiddleware();
    }
}

function ordersFlowBalance(int $variantId): array
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

    throw new RuntimeException('Inventory balance not found. Update ordersFlowBalance() to match Catalog.');
}

function ordersFlowLogin(User $user): void
{
    Auth::forgetGuards();
    test()->actingAs($user, 'customer')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
    ]);
}

function ordersFlowCustomer(): Customer
{
    $user = User::factory()->create(['role' => 'customer', 'is_active' => true]);

    return Customer::factory()->create(['user_id' => $user->id])->load('user');
}

function ordersFlowAmount(mixed $value, string $expected): void
{
    // Tests compare API money representations; production arithmetic must use minor units.
    expect(is_numeric($value))->toBeTrue();
    expect(number_format((float) $value, 2, '.', ''))->toBe($expected);
}

function ordersFlowPayload(array $overrides = []): array
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

function ordersFlowAdd(array $item, int $quantity = 2): void
{
    test()->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => $quantity])
        ->assertSuccessful();
}

function ordersFlowReviewed(array $payload): array
{
    $review = test()->postJson('/api/checkout/review', $payload)->assertOk();
    $token = $review->json('data.review_token');
    expect($token)->toBeString()->not->toBeEmpty();

    return [...$payload, 'review_token' => $token, 'idempotency_key' => 'test-'.Str::uuid()];
}

function ordersFlowNoOrderWrites(array $counts): void
{
    foreach ($counts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
}

function ordersFlowCounts(): array
{
    return collect(['orders', 'order_items', 'payments'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
}

function ordersFlowPlace(?Customer $customer = null, array $overrides = []): array
{
    if ($customer !== null) {
        ordersFlowLogin($customer->user);
    }
    $item = ordersFlowFixture();
    ordersFlowAdd($item);
    $payload = ordersFlowPayload($overrides);
    if ($customer !== null) {
        unset($payload['contact']);
    }
    $request = ordersFlowReviewed($payload);
    $response = test()->postJson('/api/checkout', $request)->assertCreated();

    return [
        'id' => $response->json('data.order.id'),
        'number' => $response->json('data.order.order_number'),
        'token' => $response->json('data.guest_access_token'),
        'order' => $response->json('data.order'), 'request' => $request, 'item' => $item,
    ];
}

function ordersFlowStaff(array $permissions = ['orders.view', 'orders.process']): User
{
    $staff = User::factory()->create(['role' => 'staff', 'is_active' => true, 'permissions' => $permissions]);
    Auth::forgetGuards();
    test()->actingAs($staff, 'staff')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($staff),
    ]);

    return $staff;
}

function ordersFlowAssertPrivate(mixed $data): void
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
        ordersFlowAssertPrivate($value);
    }
}

function ordersFlowAdvance(array $placed, string $target): void
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

function ordersFlowDetails(array $placed): array
{
    return test()->getJson('/api/orders/'.$placed['id'])->assertOk()->json('data.order');
}

function ordersFlowShipping(array $placed): array
{
    $raw = DB::table('orders')->where('id', $placed['id'])->value('shipping_address');

    return is_array($raw) ? $raw : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

it('completes registered checkout staff fulfillment and customer history while COD stays unpaid', function (): void {
    $customer = ordersFlowCustomer();
    $placed = ordersFlowPlace($customer);
    $stockAfterPlacement = ordersFlowBalance($placed['item']['variant_id']);
    $this->getJson('/api/customer/orders')->assertOk()->assertJsonPath('data.pagination.total', 1);
    $this->getJson('/api/customer/orders/'.$placed['id'])->assertOk()->assertJsonPath('data.order.status', 'pending');
    ordersFlowStaff();
    $this->getJson('/api/orders?'.http_build_query(['search' => $placed['number']]))->assertOk()->assertJsonCount(1, 'data.orders');
    $this->postJson('/api/orders/'.$placed['id'].'/notes', ['note' => 'INTERNAL-FLOW-NOTE'])->assertOk();
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'confirmed'])->assertOk();
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'processing'])->assertOk();
    $this->patchJson('/api/orders/'.$placed['id'].'/delivery', [
        'courier_name' => 'Pathao Courier', 'tracking_reference' => 'FLOW-TRACK-001',
    ])->assertOk();
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'shipped'])->assertOk();
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'delivered'])
        ->assertOk()->assertJsonPath('data.order.payment_status', 'unpaid');
    expect(ordersFlowBalance($placed['item']['variant_id']))->toBe([
        'on_hand' => $stockAfterPlacement['on_hand'] - 2, 'reserved' => $stockAfterPlacement['reserved'] - 2,
    ]);
    ordersFlowLogin($customer->user);
    $this->getJson('/api/customer/orders/'.$placed['id'])->assertOk()->assertJsonPath('data.order.status', 'delivered')
        ->assertDontSee('INTERNAL-FLOW-NOTE')->assertSee('FLOW-TRACK-001');
    $this->getJson('/api/customer/payments')->assertOk()->assertJsonCount(1, 'data.payments')
        ->assertJsonPath('data.payments.0.status', 'pending');
});

it('recovers a guest order on retry and completes failed delivery reattempt and final delivery', function (): void {
    $placed = ordersFlowPlace();
    $counts = ordersFlowCounts();
    $this->postJson('/api/checkout', $placed['request'])->assertOk()
        ->assertJsonPath('data.order.id', $placed['id'])->assertJsonPath('data.guest_access_token', $placed['token']);
    ordersFlowNoOrderWrites($counts);
    $this->withHeader('X-Guest-Order-Token', $placed['token'])->getJson('/api/guest/orders/'.$placed['number'])->assertOk();
    ordersFlowStaff();
    ordersFlowAdvance($placed, 'shipped');
    $shippedBalance = ordersFlowBalance($placed['item']['variant_id']);
    $this->patchJson('/api/orders/'.$placed['id'].'/delivery', [
        'delivery_status' => 'failed', 'note' => 'FLOW-FAILED-ATTEMPT',
    ])->assertOk();
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'delivered'])->assertConflict();
    $this->patchJson('/api/orders/'.$placed['id'].'/delivery', [
        'delivery_status' => 'in_transit', 'note' => 'FLOW-SECOND-ATTEMPT',
    ])->assertOk();
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'delivered'])
        ->assertOk()->assertSee('FLOW-FAILED-ATTEMPT')->assertJsonPath('data.order.payment_status', 'unpaid');
    expect(ordersFlowBalance($placed['item']['variant_id']))->toBe($shippedBalance);
    $this->withHeader('X-Guest-Order-Token', $placed['token'])->getJson('/api/guest/orders/'.$placed['number'])
        ->assertOk()->assertJsonPath('data.order.status', 'delivered');
});

it('cancels an early customer order once releases inventory and permits a replacement purchase', function (): void {
    $customer = ordersFlowCustomer();
    $placed = ordersFlowPlace($customer);
    $reserved = ordersFlowBalance($placed['item']['variant_id']);
    $this->postJson('/api/customer/orders/'.$placed['id'].'/cancel', ['reason' => 'Wrong delivery details'])
        ->assertOk()->assertJsonPath('data.order.status', 'cancelled');
    expect(ordersFlowBalance($placed['item']['variant_id']))->toBe([
        'on_hand' => $reserved['on_hand'], 'reserved' => $reserved['reserved'] - 2,
    ]);
    // Idempotent checkout recovery must not recreate a cancelled purchase.
    $this->postJson('/api/checkout', $placed['request'])->assertOk()
        ->assertJsonPath('data.order.id', $placed['id'])->assertJsonPath('data.order.status', 'cancelled');
    ordersFlowAdd($placed['item'], 1);
    $payload = ordersFlowPayload(['delivery' => ['address_line1' => 'Corrected house']]);
    unset($payload['contact']);
    $replacement = $this->postJson('/api/checkout', ordersFlowReviewed($payload))->assertCreated();
    expect($replacement->json('data.order.id'))->not->toBe($placed['id']);
    $this->getJson('/api/customer/orders')->assertOk()->assertJsonPath('data.pagination.total', 2);
});

it('requires a fresh price review before placing the purchase after a catalog price change', function (): void {
    $item = ordersFlowFixture();
    ordersFlowAdd($item);
    $oldRequest = ordersFlowReviewed(ordersFlowPayload());
    DB::table(ordersFlowVariantTable())->where('id', $item['variant_id'])->update(['price' => 1200]);
    $counts = ordersFlowCounts();
    $this->postJson('/api/checkout', $oldRequest)->assertUnprocessable()->assertJsonValidationErrors('review_token');
    ordersFlowNoOrderWrites($counts);
    $newRequest = ordersFlowReviewed(ordersFlowPayload());
    $newRequest['idempotency_key'] = $oldRequest['idempotency_key'];
    $response = $this->postJson('/api/checkout', $newRequest)->assertCreated();
    ordersFlowAmount($response->json('data.order.total_amount'), '2460.00');
    $this->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'data.cart.items');
});
