<?php

declare(strict_types=1);

/**
 * Location: tests/Feature/Orders/OrderAccessTest.php
 * Run: .\vendor\bin\pest tests/Feature/Orders/OrderAccessTest.php
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

function ordersAccessVariantTable(): string
{
    foreach (['product_variants', 'variants'] as $table) {
        if (Schema::hasTable($table)) {
            return $table;
        }
    }

    throw new RuntimeException('Catalog variant table not found. Update ordersAccessVariantTable().');
}

function ordersAccessFixture(int $stock = 200, int $price = 1000, ?int $sale = null, string $status = 'published'): array
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
        $variant = DB::table(ordersAccessVariantTable())->where('sku', $sku)->first();
        expect($variant)->not->toBeNull();

        if ($stock > 0) {
            DB::table('product_variants')->where('id', $variant->id)->update(['stock_quantity' => $stock]);
        }

        return ['product_id' => (int) $product->id, 'variant_id' => (int) $variant->id, 'sku' => $sku];
    } finally {
        $test->withMiddleware();
    }
}

function ordersAccessBalance(int $variantId): array
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

    throw new RuntimeException('Inventory balance not found. Update ordersAccessBalance() to match Catalog.');
}

function ordersAccessLogin(User $user): void
{
    Auth::forgetGuards();
    test()->actingAs($user, 'customer')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
    ]);
}

function ordersAccessCustomer(): Customer
{
    $user = User::factory()->create(['role' => 'customer', 'is_active' => true]);

    return Customer::factory()->create(['user_id' => $user->id])->load('user');
}

function ordersAccessAmount(mixed $value, string $expected): void
{
    // Tests compare API money representations; production arithmetic must use minor units.
    expect(is_numeric($value))->toBeTrue();
    expect(number_format((float) $value, 2, '.', ''))->toBe($expected);
}

function ordersAccessPayload(array $overrides = []): array
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

function ordersAccessAdd(array $item, int $quantity = 2): void
{
    test()->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => $quantity])
        ->assertSuccessful();
}

function ordersAccessReviewed(array $payload): array
{
    $review = test()->postJson('/api/checkout/review', $payload)->assertOk();
    $token = $review->json('data.review_token');
    expect($token)->toBeString()->not->toBeEmpty();

    return [...$payload, 'review_token' => $token, 'idempotency_key' => 'test-'.Str::uuid()];
}

function ordersAccessNoOrderWrites(array $counts): void
{
    foreach ($counts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
}

function ordersAccessCounts(): array
{
    return collect(['orders', 'order_items', 'payments'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
}

function ordersAccessPlace(?Customer $customer = null, array $overrides = []): array
{
    if ($customer !== null) {
        ordersAccessLogin($customer->user);
    }
    $item = ordersAccessFixture();
    ordersAccessAdd($item);
    $payload = ordersAccessPayload($overrides);
    if ($customer !== null) {
        unset($payload['contact']);
    }
    $request = ordersAccessReviewed($payload);
    $response = test()->postJson('/api/checkout', $request)->assertCreated();

    return [
        'id' => $response->json('data.order.id'),
        'number' => $response->json('data.order.order_number'),
        'token' => $response->json('data.guest_access_token'),
        'order' => $response->json('data.order'), 'request' => $request, 'item' => $item,
    ];
}

function ordersAccessStaff(array $permissions = ['orders.view', 'orders.process']): User
{
    $staff = User::factory()->create(['role' => 'staff', 'is_active' => true, 'permissions' => $permissions]);
    Auth::forgetGuards();
    test()->actingAs($staff, 'staff')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($staff),
    ]);

    return $staff;
}

function ordersAccessAssertPrivate(mixed $data): void
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
        ordersAccessAssertPrivate($value);
    }
}

it('requires customer authentication for lists details and cancellation', function (): void {
    $this->getJson('/api/customer/orders')->assertUnauthorized();
    $this->getJson('/api/customer/orders/999999')->assertUnauthorized();
    $this->postJson('/api/customer/orders/999999/cancel', ['reason' => 'Cancel'])->assertUnauthorized();
});

it('lists only owned orders with pagination and excludes guest orders even when emails match', function (): void {
    $owner = ordersAccessCustomer();
    $other = ordersAccessCustomer();
    $guest = ordersAccessPlace(null, ['contact' => ['email' => $owner->user->email]]);
    $first = ordersAccessPlace($owner);
    $second = ordersAccessPlace($owner);
    $foreign = ordersAccessPlace($other);
    ordersAccessLogin($owner->user);
    $page = $this->getJson('/api/customer/orders?per_page=1&page=1')->assertOk()
        ->assertJsonCount(1, 'data.orders')->assertJsonPath('data.pagination.total', 2)
        ->assertJsonPath('data.pagination.current_page', 1)->assertJsonPath('data.pagination.last_page', 2);
    $page2 = $this->getJson('/api/customer/orders?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data.orders');
    expect(collect([$page->json('data.orders.0.id'), $page2->json('data.orders.0.id')])->sort()->values()->all())
        ->toBe(collect([$first['id'], $second['id']])->sort()->values()->all());
    $this->getJson('/api/customer/orders?page=3&per_page=1')->assertOk()->assertJsonCount(0, 'data.orders');
    foreach ([$guest, $foreign] as $hidden) {
        $this->getJson('/api/customer/orders/'.$hidden['id'])->assertNotFound();
    }
    $page->assertJsonMissingPath('data.orders.0.shipping_address')->assertJsonMissingPath('data.orders.0.delivery');
    ordersAccessAssertPrivate($page->json());
});

it('reads full owned details and refuses other customers reads and cancellations', function (): void {
    $owner = ordersAccessCustomer();
    $placed = ordersAccessPlace($owner);
    $this->getJson('/api/customer/orders/'.$placed['id'])->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.order.id', $placed['id'])
        ->assertJsonPath('data.order.order_number', $placed['number'])->assertJsonCount(1, 'data.order.items');
    ordersAccessLogin(ordersAccessCustomer()->user);
    $this->getJson('/api/customer/orders/'.$placed['id'])->assertNotFound();
    $this->postJson('/api/customer/orders/'.$placed['id'].'/cancel', ['reason' => 'Not mine'])->assertNotFound();
    $this->assertDatabaseHas('orders', ['id' => $placed['id'], 'status' => 'pending']);
});

it('allows guest access only using the matching secret in the header', function (): void {
    $placed = ordersAccessPlace();
    $url = '/api/guest/orders/'.$placed['number'];
    $this->getJson($url)->assertNotFound();
    $this->withHeader('X-Guest-Order-Token', Str::random(64))->getJson($url)->assertNotFound();
    $response = $this->withHeader('X-Guest-Order-Token', $placed['token'])->getJson($url)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.order.id', $placed['id']);
    ordersAccessAssertPrivate($response->json());
});

it('rejects tokens belonging to another guest order and nonexistent order numbers', function (): void {
    $first = ordersAccessPlace();
    $second = ordersAccessPlace();
    $this->withHeader('X-Guest-Order-Token', $first['token'])->getJson('/api/guest/orders/'.$second['number'])->assertNotFound();
    $this->withHeader('X-Guest-Order-Token', $second['token'])->getJson('/api/guest/orders/'.$first['number'])->assertNotFound();
    $this->withHeader('X-Guest-Order-Token', $first['token'])->getJson('/api/guest/orders/MISSING-'.Str::random(12))->assertNotFound();
});

it('does not accept a guest token from a query string or use it to authorize a customer route', function (): void {
    $placed = ordersAccessPlace();
    foreach (['token', 'guest_access_token'] as $field) {
        $this->getJson('/api/guest/orders/'.$placed['number'].'?'.http_build_query([$field => $placed['token']]))->assertUnprocessable();
    }
    $this->withHeader('X-Guest-Order-Token', $placed['token'])->getJson('/api/customer/orders/'.$placed['id'])->assertUnauthorized();
});

it('protects guest cancellation with the same ownership secret', function (): void {
    $placed = ordersAccessPlace();
    $url = '/api/guest/orders/'.$placed['number'].'/cancel';
    $this->postJson($url, ['reason' => 'Cancel'])->assertNotFound();
    $this->withHeader('X-Guest-Order-Token', 'wrong-secret')->postJson($url, ['reason' => 'Cancel'])->assertNotFound();
    $this->assertDatabaseHas('orders', ['id' => $placed['id'], 'status' => 'pending']);
    $this->withHeader('X-Guest-Order-Token', $placed['token'])->postJson($url, ['reason' => 'Customer request'])->assertOk()
        ->assertJsonPath('data.order.status', 'cancelled');
});

it('does not let a guest secret authorize access to a registered order', function (): void {
    $guest = ordersAccessPlace();
    $owned = ordersAccessPlace(ordersAccessCustomer());
    $this->withHeader('X-Guest-Order-Token', $guest['token'])->getJson('/api/guest/orders/'.$owned['number'])->assertNotFound();
});

it('hides internal notes and staff identities from both customer and guest histories', function (): void {
    $guest = ordersAccessPlace();
    $customer = ordersAccessCustomer();
    $owned = ordersAccessPlace($customer);
    ordersAccessStaff();
    foreach ([$guest, $owned] as $placed) {
        $this->postJson('/api/orders/'.$placed['id'].'/notes', ['note' => 'PRIVATE-NOTE-ONLY-STAFF'])->assertOk();
    }
    ordersAccessLogin($customer->user);
    $response = $this->getJson('/api/customer/orders/'.$owned['id'])->assertOk()->assertDontSee('PRIVATE-NOTE-ONLY-STAFF');
    ordersAccessAssertPrivate($response->json());
    $response = $this->withHeader('X-Guest-Order-Token', $guest['token'])->getJson('/api/guest/orders/'.$guest['number'])
        ->assertOk()->assertDontSee('PRIVATE-NOTE-ONLY-STAFF');
    ordersAccessAssertPrivate($response->json());
});

it('validates customer order pagination', function (string $query): void {
    ordersAccessLogin(ordersAccessCustomer()->user);
    $this->getJson('/api/customer/orders?'.$query)->assertUnprocessable();
})->with(['per_page=0', 'per_page=101', 'per_page=abc', 'page=0']);
