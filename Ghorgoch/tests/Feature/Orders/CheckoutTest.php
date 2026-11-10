<?php

declare(strict_types=1);

/**
 * Location: tests/Feature/Orders/CheckoutTest.php
 * Run: .\vendor\bin\pest tests/Feature/Orders/CheckoutTest.php
 * Standalone Pest file: no dependency on CartTest.php or other generated files.
 * Based on the supplied Orders API reference and previous Catalog fixture pattern.
 * Requires documented default BDT fees (Dhaka 60, other districts 120) and cart limits.
 * Middleware bypass is limited to Catalog fixture creation.
 * Application source/runtime were unavailable; these contracts have not been run here.
 */

use App\Auth\Domain\Services\AuthService;
use App\Customers\Models\Address;
use App\Customers\Models\Customer;
use App\Users\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

function ordersCheckoutVariantTable(): string
{
    foreach (['product_variants', 'variants'] as $table) {
        if (Schema::hasTable($table)) {
            return $table;
        }
    }

    throw new RuntimeException('Catalog variant table not found. Update ordersCheckoutVariantTable().');
}

function ordersCheckoutFixture(int $stock = 200, int $price = 1000, ?int $sale = null, string $status = 'published'): array
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
        $variant = DB::table(ordersCheckoutVariantTable())->where('sku', $sku)->first();
        expect($variant)->not->toBeNull();

        if ($stock > 0) {
            DB::table('product_variants')->where('id', $variant->id)->update(['stock_quantity' => $stock]);
        }

        return ['product_id' => (int) $product->id, 'variant_id' => (int) $variant->id, 'sku' => $sku];
    } finally {
        $test->withMiddleware();
    }
}

function ordersCheckoutBalance(int $variantId): array
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

    throw new RuntimeException('Inventory balance not found. Update ordersCheckoutBalance() to match Catalog.');
}

function ordersCheckoutLogin(User $user): void
{
    Auth::forgetGuards();
    test()->actingAs($user, 'customer')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
    ]);
}

function ordersCheckoutCustomer(): Customer
{
    $user = User::factory()->create(['role' => 'customer', 'is_active' => true]);

    return Customer::factory()->create(['user_id' => $user->id])->load('user');
}

function ordersCheckoutAmount(mixed $value, string $expected): void
{
    // Tests compare API money representations; production arithmetic must use minor units.
    expect(is_numeric($value))->toBeTrue();
    expect(number_format((float) $value, 2, '.', ''))->toBe($expected);
}

function ordersCheckoutPayload(array $overrides = []): array
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

function ordersCheckoutAdd(array $item, int $quantity = 2): void
{
    test()->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => $quantity])
        ->assertSuccessful();
}

function ordersCheckoutReviewed(array $payload): array
{
    $review = test()->postJson('/api/checkout/review', $payload)->assertOk();
    $token = $review->json('data.review_token');
    expect($token)->toBeString()->not->toBeEmpty();

    return [...$payload, 'review_token' => $token, 'idempotency_key' => 'test-'.Str::uuid()];
}

function ordersCheckoutNoOrderWrites(array $counts): void
{
    foreach ($counts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
}

function ordersCheckoutCounts(): array
{
    return collect(['orders', 'order_items', 'payments'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
}

it('creates a guest COD order items and payment obligation and reserves stock together', function (): void {
    $item = ordersCheckoutFixture(stock: 10);
    ordersCheckoutAdd($item);
    $before = ordersCheckoutCounts();
    $balance = ordersCheckoutBalance($item['variant_id']);
    $response = $this->postJson('/api/checkout', ordersCheckoutReviewed(ordersCheckoutPayload()))->assertCreated()
        ->assertJsonPath('data.replayed', false)->assertJsonPath('data.order.status', 'pending')
        ->assertJsonPath('data.order.payment_status', 'unpaid')->assertJsonCount(1, 'data.order.items');
    $orderId = $response->json('data.order.id');
    expect($orderId)->not->toBeNull();
    expect($response->json('data.order.order_number'))->toBeString()->not->toBeEmpty();
    expect($response->json('data.guest_access_token'))->toBeString()->not->toBeEmpty();
    expect(DB::table('orders')->count())->toBe($before['orders'] + 1);
    expect(DB::table('order_items')->count())->toBe($before['order_items'] + 1);
    expect(DB::table('payments')->count())->toBe($before['payments'] + 1);
    $this->assertDatabaseHas('orders', ['id' => $orderId, 'customer_id' => null]);
    $payment = DB::table('payments')->where('order_id', $orderId)->sole();
    expect($payment->status)->toBe('pending');
    ordersCheckoutAmount($payment->amount, '2060.00');
    expect($payment->paid_at)->toBeNull();
    expect(ordersCheckoutBalance($item['variant_id']))->toBe([
        'on_hand' => $balance['on_hand'], 'reserved' => $balance['reserved'] + 2,
    ]);
    $this->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'data.cart.items');
});

it('creates a registered order linked to the customer with its saved address', function (): void {
    $customer = ordersCheckoutCustomer();
    $address = Address::factory()->create([
        'customer_id' => $customer->id, ...ordersCheckoutPayload()['delivery'], 'postal_code' => '1216',
    ]);
    ordersCheckoutLogin($customer->user);
    ordersCheckoutAdd(ordersCheckoutFixture());
    $response = $this->postJson('/api/checkout', ordersCheckoutReviewed([
        'payment_method' => 'cod', 'address_id' => $address->id,
    ]))->assertCreated()->assertJsonMissingPath('data.guest_access_token');
    $this->assertDatabaseHas('orders', ['id' => $response->json('data.order.id'), 'customer_id' => $customer->id]);
    $this->getJson('/api/customer/orders/'.$response->json('data.order.id'))->assertOk();
});

it('does not link a guest to an account just because contact matches', function (): void {
    $customer = ordersCheckoutCustomer();
    ordersCheckoutAdd(ordersCheckoutFixture());
    $users = DB::table('users')->count();
    $customers = DB::table('customers')->count();
    $payload = ordersCheckoutPayload(['contact' => ['email' => $customer->user->email]]);
    $response = $this->postJson('/api/checkout', ordersCheckoutReviewed($payload))->assertCreated();
    $this->assertDatabaseHas('orders', ['id' => $response->json('data.order.id'), 'customer_id' => null]);
    expect(DB::table('users')->count())->toBe($users);
    expect(DB::table('customers')->count())->toBe($customers);
});

it('snapshots multiple lines and the complete server calculated COD total', function (): void {
    $first = ordersCheckoutFixture(price: 1000, sale: 850);
    $second = ordersCheckoutFixture(price: 500);
    ordersCheckoutAdd($first, 2);
    ordersCheckoutAdd($second, 3);
    $response = $this->postJson('/api/checkout', ordersCheckoutReviewed(ordersCheckoutPayload()))->assertCreated()
        ->assertJsonCount(2, 'data.order.items');
    ordersCheckoutAmount($response->json('data.order.subtotal'), '3200.00');
    ordersCheckoutAmount($response->json('data.order.shipping_fee'), '60.00');
    ordersCheckoutAmount($response->json('data.order.tax_amount'), '0.00');
    ordersCheckoutAmount($response->json('data.order.total_amount'), '3260.00');
    $lines = collect($response->json('data.order.items'))->keyBy('sku');
    expect($lines[$first['sku']]['quantity'])->toBe(2);
    expect($lines[$second['sku']]['quantity'])->toBe(3);
    ordersCheckoutAmount($lines[$first['sku']]['unit_price'], '850.00');
    ordersCheckoutAmount($lines[$first['sku']]['line_total'], '1700.00');
});

it('preserves historical item values after catalog product and price edits', function (): void {
    $item = ordersCheckoutFixture();
    ordersCheckoutAdd($item);
    $response = $this->postJson('/api/checkout', ordersCheckoutReviewed(ordersCheckoutPayload()))->assertCreated();
    $items = $response->json('data.order.items');
    $token = $response->json('data.guest_access_token');
    $number = $response->json('data.order.order_number');
    DB::table('products')->where('id', $item['product_id'])->update(['name' => 'Renamed Product']);
    DB::table(ordersCheckoutVariantTable())->where('id', $item['variant_id'])->update(['price' => 1800]);
    $this->withHeader('X-Guest-Order-Token', $token)->getJson('/api/guest/orders/'.$number)->assertOk()
        ->assertJsonPath('data.order.items', $items);
});

it('requires review token and idempotency key before placement', function (): void {
    ordersCheckoutAdd(ordersCheckoutFixture());
    $counts = ordersCheckoutCounts();
    $this->postJson('/api/checkout', ordersCheckoutPayload())->assertUnprocessable()
        ->assertJsonValidationErrors(['review_token', 'idempotency_key']);
    ordersCheckoutNoOrderWrites($counts);
    $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.cart.items.0.quantity', 2);
});

it('rejects invalid idempotency keys without writing orders', function (string $key): void {
    ordersCheckoutAdd(ordersCheckoutFixture());
    $payload = ordersCheckoutReviewed(ordersCheckoutPayload());
    $payload['idempotency_key'] = $key;
    $counts = ordersCheckoutCounts();
    $this->postJson('/api/checkout', $payload)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    ordersCheckoutNoOrderWrites($counts);
})->with(['has spaces', 'slash/key', str_repeat('a', 101)]);

it('rejects a forged review token and retains cart contents', function (): void {
    ordersCheckoutAdd(ordersCheckoutFixture());
    $payload = ordersCheckoutReviewed(ordersCheckoutPayload());
    $payload['review_token'] = Str::random(80);
    $counts = ordersCheckoutCounts();
    $this->postJson('/api/checkout', $payload)->assertUnprocessable()->assertJsonValidationErrors('review_token');
    ordersCheckoutNoOrderWrites($counts);
    $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.cart.items.0.quantity', 2);
});

it('rechecks availability at placement and leaves no partial records', function (): void {
    $first = ordersCheckoutFixture();
    $second = ordersCheckoutFixture();
    ordersCheckoutAdd($first);
    ordersCheckoutAdd($second);
    $payload = ordersCheckoutReviewed(ordersCheckoutPayload());
    DB::table('products')->where('id', $second['product_id'])->update(['status' => 'draft']);
    $counts = ordersCheckoutCounts();
    $balance = ordersCheckoutBalance($first['variant_id']);
    $this->postJson('/api/checkout', $payload)->assertUnprocessable();
    ordersCheckoutNoOrderWrites($counts);
    expect(ordersCheckoutBalance($first['variant_id']))->toBe($balance);
    $this->getJson('/api/cart')->assertOk()->assertJsonCount(2, 'data.cart.items');
});

it('rolls back order items payment and reservations if a database write fails', function (): void {
    $item = ordersCheckoutFixture();
    ordersCheckoutAdd($item);
    $payload = ordersCheckoutReviewed(ordersCheckoutPayload());
    $counts = ordersCheckoutCounts();
    $balance = ordersCheckoutBalance($item['variant_id']);
    $armed = true;
    // Fail after the payment INSERT has executed, while checkout is still in its transaction.
    DB::listen(function (QueryExecuted $query) use (&$armed): void {
        if ($armed && preg_match('/^\s*insert\s+into\s+["`]?payments["`]?\s/i', $query->sql)) {
            $armed = false;
            throw new RuntimeException('Injected checkout payment write failure');
        }
    });
    $this->withoutExceptionHandling();
    try {
        $this->postJson('/api/checkout', $payload);
        $this->fail('Expected the injected payment failure to abort checkout.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Injected checkout payment write failure');
    } finally {
        $armed = false;
        $this->withExceptionHandling();
    }
    ordersCheckoutNoOrderWrites($counts);
    expect(ordersCheckoutBalance($item['variant_id']))->toBe($balance);
    $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.cart.items.0.quantity', 2);
    // The failed purchase must not consume the idempotency key or review.
    $this->postJson('/api/checkout', $payload)->assertCreated();
});

it('allows a fresh purchase after successful cart conversion and assigns unique order numbers', function (): void {
    $item = ordersCheckoutFixture();
    ordersCheckoutAdd($item, 1);
    $first = $this->postJson('/api/checkout', ordersCheckoutReviewed(ordersCheckoutPayload()))->assertCreated();
    ordersCheckoutAdd($item, 1);
    $second = $this->postJson('/api/checkout', ordersCheckoutReviewed(ordersCheckoutPayload()))->assertCreated();
    expect($second->json('data.order.id'))->not->toBe($first->json('data.order.id'));
    expect($second->json('data.order.order_number'))->not->toBe($first->json('data.order.order_number'));
});

function ordersCheckoutSetOnHand(int $variantId, int $quantity): void
{
    foreach (['inventories', 'inventory', 'inventory_stocks', 'stock_levels', 'product_variants', 'variants'] as $table) {
        if (! Schema::hasTable($table)) {
            continue;
        }
        $key = in_array($table, ['product_variants', 'variants'], true) ? 'id' : 'variant_id';
        if (! Schema::hasColumn($table, $key)) {
            continue;
        }
        if (! DB::table($table)->where($key, $variantId)->exists()) {
            continue;
        }
        foreach (['on_hand', 'on_hand_stock', 'stock_on_hand', 'stock_quantity', 'quantity_on_hand'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                DB::table($table)->where($key, $variantId)->update([$column => $quantity]);

                return;
            }
        }
    }
    throw new RuntimeException('Adapt ordersCheckoutSetOnHand() to the Catalog stock schema.');
}

it('rejects insufficient stock after cart creation without leaving partial checkout writes', function (): void {
    $item = ordersCheckoutFixture(stock: 10);
    ordersCheckoutAdd($item, 2);
    $payload = ordersCheckoutReviewed(ordersCheckoutPayload());
    ordersCheckoutSetOnHand($item['variant_id'], 1);
    $balance = ordersCheckoutBalance($item['variant_id']);
    $counts = ordersCheckoutCounts();
    $this->postJson('/api/checkout', $payload)->assertUnprocessable();
    ordersCheckoutNoOrderWrites($counts);
    expect(ordersCheckoutBalance($item['variant_id']))->toBe($balance);
    $this->getJson('/api/cart')->assertOk()->assertJsonCount(1, 'data.cart.items')
        ->assertJsonPath('data.cart.items.0.quantity', 2)->assertJsonPath('data.cart.can_checkout', false);
});
