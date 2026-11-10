<?php

declare(strict_types=1);

/**
 * Location: tests/Feature/Orders/CheckoutReviewTest.php
 * Run: .\vendor\bin\pest tests/Feature/Orders/CheckoutReviewTest.php
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
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

function ordersReviewVariantTable(): string
{
    foreach (['product_variants', 'variants'] as $table) {
        if (Schema::hasTable($table)) {
            return $table;
        }
    }

    throw new RuntimeException('Catalog variant table not found. Update ordersReviewVariantTable().');
}

function ordersReviewFixture(int $stock = 200, int $price = 1000, ?int $sale = null, string $status = 'published'): array
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
        $variant = DB::table(ordersReviewVariantTable())->where('sku', $sku)->first();
        expect($variant)->not->toBeNull();

        if ($stock > 0) {
            DB::table('product_variants')->where('id', $variant->id)->update(['stock_quantity' => $stock]);
        }

        return ['product_id' => (int) $product->id, 'variant_id' => (int) $variant->id, 'sku' => $sku];
    } finally {
        $test->withMiddleware();
    }
}

function ordersReviewBalance(int $variantId): array
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

    throw new RuntimeException('Inventory balance not found. Update ordersReviewBalance() to match Catalog.');
}

function ordersReviewLogin(User $user): void
{
    Auth::forgetGuards();
    test()->actingAs($user, 'customer')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
    ]);
}

function ordersReviewCustomer(): Customer
{
    $user = User::factory()->create(['role' => 'customer', 'is_active' => true]);

    return Customer::factory()->create(['user_id' => $user->id])->load('user');
}

function ordersReviewAmount(mixed $value, string $expected): void
{
    // Tests compare API money representations; production arithmetic must use minor units.
    expect(is_numeric($value))->toBeTrue();
    expect(number_format((float) $value, 2, '.', ''))->toBe($expected);
}

function ordersReviewPayload(array $overrides = []): array
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

function ordersReviewAdd(array $item, int $quantity = 2): void
{
    test()->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => $quantity])
        ->assertSuccessful();
}

function ordersReviewReviewed(array $payload): array
{
    $review = test()->postJson('/api/checkout/review', $payload)->assertOk();
    $token = $review->json('data.review_token');
    expect($token)->toBeString()->not->toBeEmpty();

    return [...$payload, 'review_token' => $token, 'idempotency_key' => 'test-'.Str::uuid()];
}

function ordersReviewNoOrderWrites(array $counts): void
{
    foreach ($counts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
}

function ordersReviewCounts(): array
{
    return collect(['orders', 'order_items', 'payments'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
}

it('reviews guest COD totals delivery fees and sale prices without reserving or writing orders', function (): void {
    $item = ordersReviewFixture(price: 1000, sale: 850);
    ordersReviewAdd($item);
    $balance = ordersReviewBalance($item['variant_id']);
    $counts = ordersReviewCounts();
    $response = $this->postJson('/api/checkout/review', ordersReviewPayload())->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.payment_method', 'cod')
        ->assertJsonPath('data.currency', 'BDT')->assertJsonCount(1, 'data.items');
    foreach (['review_token', 'expires_at'] as $field) {
        expect($response->json('data.'.$field))->toBeString()->not->toBeEmpty();
    }
    foreach (['subtotal' => '1700.00', 'shipping_fee' => '60.00', 'tax_amount' => '0.00', 'total_amount' => '1760.00'] as $key => $value) {
        ordersReviewAmount($response->json('data.'.$key), $value);
    }
    expect(ordersReviewBalance($item['variant_id']))->toBe($balance);
    ordersReviewNoOrderWrites($counts);
    $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.cart.items.0.quantity', 2);
});

it('uses the default delivery charge outside Dhaka', function (): void {
    ordersReviewAdd(ordersReviewFixture());
    $response = $this->postJson('/api/checkout/review', ordersReviewPayload([
        'delivery' => ['city' => 'Sylhet', 'district' => 'Sylhet', 'division' => 'Sylhet'],
    ]))->assertOk();
    ordersReviewAmount($response->json('data.shipping_fee'), '120.00');
    ordersReviewAmount($response->json('data.total_amount'), '2120.00');
});

it('rejects review of an empty cart', function (): void {
    $counts = ordersReviewCounts();
    $this->postJson('/api/checkout/review', ordersReviewPayload())->assertUnprocessable();
    ordersReviewNoOrderWrites($counts);
});

it('rejects unsupported payment methods', function (string $method): void {
    ordersReviewAdd(ordersReviewFixture());
    $this->postJson('/api/checkout/review', ordersReviewPayload(['payment_method' => $method]))
        ->assertUnprocessable()->assertJsonValidationErrors('payment_method');
})->with(['bkash', 'nagad', 'card', 'cash', '']);

it('validates required delivery and guest contact fields', function (string $field, mixed $value): void {
    ordersReviewAdd(ordersReviewFixture());
    $payload = ordersReviewPayload();
    data_set($payload, $field, $value);
    $this->postJson('/api/checkout/review', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['contact.name', ''], ['contact.phone', '01712345678'], ['contact.email', 'invalid-email'],
    ['delivery.recipient_name', ''], ['delivery.phone', '01712345678'], ['delivery.address_line1', ''],
    ['delivery.city', ''], ['delivery.district', ''], ['delivery.division', ''], ['delivery.country_code', 'US'],
]);

it('allows guest email to be omitted and notes to be supplied', function (): void {
    ordersReviewAdd(ordersReviewFixture());
    $payload = ordersReviewPayload(['customer_note' => 'Please call first.']);
    unset($payload['contact']['email']);
    $this->postJson('/api/checkout/review', $payload)->assertOk();
});

it('rejects overlong customer notes', function (): void {
    ordersReviewAdd(ordersReviewFixture());
    $this->postJson('/api/checkout/review', ordersReviewPayload(['customer_note' => str_repeat('a', 1001)]))
        ->assertUnprocessable()->assertJsonValidationErrors('customer_note');
});

it('rejects client totals and account selection in a guest review', function (string $field, mixed $value): void {
    ordersReviewAdd(ordersReviewFixture());
    $this->postJson('/api/checkout/review', [...ordersReviewPayload(), $field => $value])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([['total_amount', 1], ['shipping_fee', 0], ['customer_id', 999], ['address_id', 999]]);

it('reviews a registered customers own saved address and rejects another customers address', function (): void {
    $customer = ordersReviewCustomer();
    $other = ordersReviewCustomer();
    $owned = Address::factory()->create([
        'customer_id' => $customer->id, ...ordersReviewPayload()['delivery'],
    ]);
    $foreign = Address::factory()->create([
        'customer_id' => $other->id, ...ordersReviewPayload()['delivery'],
    ]);
    ordersReviewLogin($customer->user);
    ordersReviewAdd(ordersReviewFixture());
    $this->postJson('/api/checkout/review', ['payment_method' => 'cod', 'address_id' => $owned->id])->assertOk();
    $this->postJson('/api/checkout/review', ['payment_method' => 'cod', 'address_id' => $foreign->id])->assertNotFound();
    $this->postJson('/api/checkout/review', [
        'payment_method' => 'cod', 'address_id' => $owned->id, 'contact' => ordersReviewPayload()['contact'],
    ])->assertUnprocessable()->assertJsonValidationErrors('contact');
});

it('rejects products unpublished after being added to the cart', function (): void {
    $item = ordersReviewFixture();
    ordersReviewAdd($item);
    DB::table('products')->where('id', $item['product_id'])->update(['status' => 'draft']);
    $this->postJson('/api/checkout/review', ordersReviewPayload())->assertUnprocessable();
});

it('requires a new review when catalog prices change before placement', function (): void {
    $item = ordersReviewFixture();
    ordersReviewAdd($item);
    $payload = ordersReviewReviewed(ordersReviewPayload());
    DB::table(ordersReviewVariantTable())->where('id', $item['variant_id'])->update(['price' => 1100]);
    $counts = ordersReviewCounts();
    $this->postJson('/api/checkout', $payload)->assertUnprocessable()->assertJsonValidationErrors('review_token');
    ordersReviewNoOrderWrites($counts);
    $newReview = $this->postJson('/api/checkout/review', ordersReviewPayload())->assertOk();
    ordersReviewAmount($newReview->json('data.total_amount'), '2260.00');
});

it('rejects a reviewed checkout after cart quantities change', function (): void {
    $item = ordersReviewFixture();
    ordersReviewAdd($item);
    $payload = ordersReviewReviewed(ordersReviewPayload());
    $this->patchJson('/api/cart/items/'.$item['variant_id'], ['quantity' => 3])->assertOk();
    $this->postJson('/api/checkout', $payload)->assertUnprocessable()->assertJsonValidationErrors('review_token');
});

it('rejects delivery contact and note changes made after review', function (string $field, string $value): void {
    ordersReviewAdd(ordersReviewFixture());
    $payload = ordersReviewReviewed(ordersReviewPayload());
    data_set($payload, $field, $value);
    $this->postJson('/api/checkout', $payload)->assertUnprocessable()->assertJsonValidationErrors('review_token');
})->with([
    ['delivery.address_line1', 'Different house'], ['contact.name', 'Different person'], ['customer_note', 'Added after review'],
]);

it('rejects expired review tokens without clearing the cart', function (): void {
    ordersReviewAdd(ordersReviewFixture());
    $payload = ordersReviewReviewed(ordersReviewPayload());
    $counts = ordersReviewCounts();
    try {
        $this->travel(16)->minutes();
        $this->postJson('/api/checkout', $payload)->assertUnprocessable()->assertJsonValidationErrors('review_token');
        ordersReviewNoOrderWrites($counts);
        $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.cart.items.0.quantity', 2);
    } finally {
        $this->travelBack();
    }
});

function ordersReviewSetOnHand(int $variantId, int $quantity): void
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
    throw new RuntimeException('Adapt ordersReviewSetOnHand() to the Catalog stock schema.');
}

it('rejects insufficient stock after cart creation without leaving partial checkout writes', function (): void {
    $item = ordersReviewFixture(stock: 10);
    ordersReviewAdd($item, 2);
    $payload = ordersReviewPayload();
    ordersReviewSetOnHand($item['variant_id'], 1);
    $balance = ordersReviewBalance($item['variant_id']);
    $counts = ordersReviewCounts();
    $this->postJson('/api/checkout/review', $payload)->assertUnprocessable();
    ordersReviewNoOrderWrites($counts);
    expect(ordersReviewBalance($item['variant_id']))->toBe($balance);
    $this->getJson('/api/cart')->assertOk()->assertJsonCount(1, 'data.cart.items')
        ->assertJsonPath('data.cart.items.0.quantity', 2)->assertJsonPath('data.cart.can_checkout', false);
});
