<?php

declare(strict_types=1);

/**
 * Location: tests/Feature/Orders/StaffOrderManagementTest.php
 * Run: .\vendor\bin\pest tests/Feature/Orders/StaffOrderManagementTest.php
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

function ordersStaffVariantTable(): string
{
    foreach (['product_variants', 'variants'] as $table) {
        if (Schema::hasTable($table)) {
            return $table;
        }
    }

    throw new RuntimeException('Catalog variant table not found. Update ordersStaffVariantTable().');
}

function ordersStaffFixture(int $stock = 200, int $price = 1000, ?int $sale = null, string $status = 'published'): array
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
        $variant = DB::table(ordersStaffVariantTable())->where('sku', $sku)->first();
        expect($variant)->not->toBeNull();

        if ($stock > 0) {
            DB::table('product_variants')->where('id', $variant->id)->update(['stock_quantity' => $stock]);
        }

        return ['product_id' => (int) $product->id, 'variant_id' => (int) $variant->id, 'sku' => $sku];
    } finally {
        $test->withMiddleware();
    }
}

function ordersStaffBalance(int $variantId): array
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

    throw new RuntimeException('Inventory balance not found. Update ordersStaffBalance() to match Catalog.');
}

function ordersStaffLogin(User $user): void
{
    Auth::forgetGuards();
    test()->actingAs($user, 'customer')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
    ]);
}

function ordersStaffCustomer(): Customer
{
    $user = User::factory()->create(['role' => 'customer', 'is_active' => true]);

    return Customer::factory()->create(['user_id' => $user->id])->load('user');
}

function ordersStaffAmount(mixed $value, string $expected): void
{
    // Tests compare API money representations; production arithmetic must use minor units.
    expect(is_numeric($value))->toBeTrue();
    expect(number_format((float) $value, 2, '.', ''))->toBe($expected);
}

function ordersStaffPayload(array $overrides = []): array
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

function ordersStaffAdd(array $item, int $quantity = 2): void
{
    test()->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => $quantity])
        ->assertSuccessful();
}

function ordersStaffReviewed(array $payload): array
{
    $review = test()->postJson('/api/checkout/review', $payload)->assertOk();
    $token = $review->json('data.review_token');
    expect($token)->toBeString()->not->toBeEmpty();

    return [...$payload, 'review_token' => $token, 'idempotency_key' => 'test-'.Str::uuid()];
}

function ordersStaffNoOrderWrites(array $counts): void
{
    foreach ($counts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
}

function ordersStaffCounts(): array
{
    return collect(['orders', 'order_items', 'payments'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
}

function ordersStaffPlace(?Customer $customer = null, array $overrides = []): array
{
    if ($customer !== null) {
        ordersStaffLogin($customer->user);
    }
    $item = ordersStaffFixture();
    ordersStaffAdd($item);
    $payload = ordersStaffPayload($overrides);
    if ($customer !== null) {
        unset($payload['contact']);
    }
    $request = ordersStaffReviewed($payload);
    $response = test()->postJson('/api/checkout', $request)->assertCreated();

    return [
        'id' => $response->json('data.order.id'),
        'number' => $response->json('data.order.order_number'),
        'token' => $response->json('data.guest_access_token'),
        'order' => $response->json('data.order'), 'request' => $request, 'item' => $item,
    ];
}

function ordersStaffStaff(array $permissions = ['orders.view', 'orders.process']): User
{
    $staff = User::factory()->create(['role' => 'staff', 'is_active' => true, 'permissions' => $permissions]);
    Auth::forgetGuards();
    test()->actingAs($staff, 'staff')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($staff),
    ]);

    return $staff;
}

function ordersStaffAssertPrivate(mixed $data): void
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
        ordersStaffAssertPrivate($value);
    }
}

it('requires staff authentication on order administration routes', function (): void {
    $this->getJson('/api/orders')->assertUnauthorized();
    $this->getJson('/api/orders/999999')->assertUnauthorized();
    $this->postJson('/api/orders/999999/notes', ['note' => 'Private'])->assertUnauthorized();
});

it('enforces separate view and process permissions', function (): void {
    $placed = ordersStaffPlace();
    ordersStaffStaff([]);
    $this->getJson('/api/orders')->assertForbidden();
    $this->getJson('/api/orders/'.$placed['id'])->assertForbidden();
    $this->postJson('/api/orders/'.$placed['id'].'/notes', ['note' => 'Denied'])->assertForbidden();
    ordersStaffStaff(['orders.view']);
    $this->getJson('/api/orders')->assertOk();
    $this->getJson('/api/orders/'.$placed['id'])->assertOk();
    $this->postJson('/api/orders/'.$placed['id'].'/notes', ['note' => 'Denied'])->assertForbidden();
    $this->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => 'confirmed'])->assertForbidden();
    $this->assertDatabaseHas('orders', ['id' => $placed['id'], 'status' => 'pending']);
});

it('lists orders newest first and paginates without losing matching records', function (): void {
    $first = ordersStaffPlace();
    $second = ordersStaffPlace();
    DB::table('orders')->where('id', $first['id'])->update(['created_at' => '2026-10-01 10:00:00']);
    DB::table('orders')->where('id', $second['id'])->update(['created_at' => '2026-10-02 10:00:00']);
    ordersStaffStaff(['orders.view']);
    $this->getJson('/api/orders?per_page=1')->assertOk()->assertJsonCount(1, 'data.orders')
        ->assertJsonPath('data.orders.0.id', $second['id'])->assertJsonPath('data.pagination.total', 2)
        ->assertJsonPath('data.pagination.last_page', 2);
    $this->getJson('/api/orders?per_page=1&page=2')->assertOk()->assertJsonPath('data.orders.0.id', $first['id']);
    $this->getJson('/api/orders?per_page=1&page=3')->assertOk()->assertJsonCount(0, 'data.orders');
});

it('searches by order number and returns full staff details', function (): void {
    $placed = ordersStaffPlace();
    ordersStaffPlace();
    ordersStaffStaff(['orders.view']);
    $this->getJson('/api/orders?'.http_build_query(['search' => $placed['number']]))->assertOk()
        ->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.id', $placed['id']);
    $this->getJson('/api/orders/'.$placed['id'])->assertOk()->assertJsonCount(1, 'data.order.items')
        ->assertJsonPath('data.order.order_number', $placed['number'])->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonMissingPath('data.order.guest_access_token');
    $this->getJson('/api/orders/999999999')->assertNotFound();
});

it('filters by customer and distinguishes registered orders from guest orders', function (): void {
    ordersStaffPlace();
    $customer = ordersStaffCustomer();
    $owned = ordersStaffPlace($customer);
    ordersStaffPlace(ordersStaffCustomer());
    ordersStaffStaff(['orders.view']);
    $this->getJson('/api/orders?customer_id='.$customer->id)->assertOk()->assertJsonCount(1, 'data.orders')
        ->assertJsonPath('data.orders.0.id', $owned['id']);
});

it('filters independently by contact and delivery phone', function (): void {
    $first = ordersStaffPlace(null, ['contact' => ['phone' => '+8801811111111'], 'delivery' => ['phone' => '+8801911111111']]);
    ordersStaffPlace(null, ['contact' => ['phone' => '+8801711111111'], 'delivery' => ['phone' => '+8801611111111']]);
    ordersStaffStaff(['orders.view']);
    foreach (['+8801811111111', '+8801911111111'] as $phone) {
        $this->getJson('/api/orders?'.http_build_query(['phone' => $phone]))->assertOk()->assertJsonCount(1, 'data.orders')
            ->assertJsonPath('data.orders.0.id', $first['id']);
    }
});

it('filters fulfillment and payment status separately and combines filters', function (): void {
    $first = ordersStaffPlace();
    $second = ordersStaffPlace();
    ordersStaffStaff();
    $this->patchJson('/api/orders/'.$second['id'].'/status', ['status' => 'confirmed'])->assertOk();
    $this->getJson('/api/orders?status=pending')->assertOk()->assertJsonCount(1, 'data.orders')
        ->assertJsonPath('data.orders.0.id', $first['id']);
    $this->getJson('/api/orders?status=confirmed&payment_status=unpaid')->assertOk()->assertJsonCount(1, 'data.orders')
        ->assertJsonPath('data.orders.0.id', $second['id']);
    $this->getJson('/api/orders?payment_status=paid')->assertOk()->assertJsonCount(0, 'data.orders');
    $this->getJson('/api/orders?'.http_build_query(['status' => 'pending', 'search' => $second['number']]))
        ->assertOk()->assertJsonCount(0, 'data.orders');
});

it('applies inclusive UTC date bounds including from only and to only', function (): void {
    $early = ordersStaffPlace();
    $middle = ordersStaffPlace();
    $late = ordersStaffPlace();
    DB::table('orders')->where('id', $early['id'])->update(['created_at' => '2026-10-01 23:59:59']);
    DB::table('orders')->where('id', $middle['id'])->update(['created_at' => '2026-10-02 12:00:00']);
    DB::table('orders')->where('id', $late['id'])->update(['created_at' => '2026-10-03 00:00:00']);
    ordersStaffStaff(['orders.view']);
    $this->getJson('/api/orders?from=2026-10-02&to=2026-10-02')->assertOk()->assertJsonCount(1, 'data.orders')
        ->assertJsonPath('data.orders.0.id', $middle['id']);
    $this->getJson('/api/orders?from=2026-10-02')->assertOk()->assertJsonCount(2, 'data.orders');
    $this->getJson('/api/orders?to=2026-10-02')->assertOk()->assertJsonCount(2, 'data.orders');
});

it('validates pagination date bounds and status filters', function (array $query): void {
    ordersStaffStaff(['orders.view']);
    $this->getJson('/api/orders?'.http_build_query($query))->assertUnprocessable();
})->with([
    [['per_page' => 0]], [['per_page' => 101]], [['page' => 0]], [['status' => 'invalid-status']],
    [['payment_status' => 'invalid-status']], [['from' => 'not-a-date']],
    [['from' => '2026-10-03', 'to' => '2026-10-02']], [['search' => str_repeat('x', 151)]],
]);

it('creates private staff notes without changing order totals or inventory', function (): void {
    $placed = ordersStaffPlace();
    ordersStaffStaff();
    $balance = ordersStaffBalance($placed['item']['variant_id']);
    $response = $this->postJson('/api/orders/'.$placed['id'].'/notes', ['note' => 'PRIVATE-STAFF-NOTE'])
        ->assertOk()->assertSee('PRIVATE-STAFF-NOTE')->assertJsonPath('data.order.status', 'pending');
    ordersStaffAmount($response->json('data.order.total_amount'), '2060.00');
    expect(ordersStaffBalance($placed['item']['variant_id']))->toBe($balance);
    $this->getJson('/api/orders/'.$placed['id'])->assertOk()->assertSee('PRIVATE-STAFF-NOTE');
});

it('rejects invalid notes and caller selected actor ids', function (): void {
    $placed = ordersStaffPlace();
    ordersStaffStaff();
    foreach (['', '   ', str_repeat('x', 5001)] as $note) {
        $this->postJson('/api/orders/'.$placed['id'].'/notes', ['note' => $note])->assertUnprocessable()->assertJsonValidationErrors('note');
    }
    $this->postJson('/api/orders/'.$placed['id'].'/notes', ['note' => 'Valid note', 'actor_id' => 999])
        ->assertUnprocessable()->assertJsonValidationErrors('actor_id');
});

it('requires both customer and order view permissions for the customer shortcut', function (): void {
    $owner = ordersStaffCustomer();
    $owned = ordersStaffPlace($owner);
    ordersStaffPlace(ordersStaffCustomer());
    foreach ([['customers.view'], ['orders.view']] as $permissions) {
        ordersStaffStaff($permissions);
        $this->getJson('/api/customers/'.$owner->id.'/orders')->assertForbidden();
    }
    ordersStaffStaff(['customers.view', 'orders.view']);
    $this->getJson('/api/customers/'.$owner->id.'/orders?per_page=1')->assertOk()->assertJsonCount(1, 'data.orders')
        ->assertJsonPath('data.orders.0.id', $owned['id'])->assertJsonPath('data.pagination.total', 1);
});

it('does not allow customer sessions to use staff order routes', function (): void {
    $placed = ordersStaffPlace(ordersStaffCustomer());
    $this->getJson('/api/orders')->assertUnauthorized();
    $this->getJson('/api/orders/'.$placed['id'])->assertUnauthorized();
    $this->postJson('/api/orders/'.$placed['id'].'/notes', ['note' => 'Not allowed'])->assertUnauthorized();
});
