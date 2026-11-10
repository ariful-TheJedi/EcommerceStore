<?php

declare(strict_types=1);

use App\Auth\Domain\Services\AuthService;
use App\Customers\Models\Address;
use App\Customers\Models\Customer;
use App\Orders\Domain\Services\OrderCustomerService;
use App\Orders\Models\Order;
use App\Payments\Models\Payment;
use App\Users\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);

function customerIntegrationAccount(array $fields = []): Customer
{
    $user = User::factory()->create([
        'role' => 'customer',
        ...$fields,
    ]);

    return Customer::factory()
        ->create(['user_id' => $user->id])
        ->load('user');
}

function customerIntegrationLogin(
    $test,
    User $user,
    string $guard = 'customer',
): void {
    Auth::forgetGuards();

    $test->actingAs($user, $guard)->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
    ]);
}

function customerIntegrationStaff($test, array $permissions): User
{
    $staff = User::factory()->create([
        'role' => 'staff',
        'permissions' => $permissions,
    ]);

    customerIntegrationLogin($test, $staff, 'staff');

    return $staff;
}

function customerIntegrationDelivery(array $overrides = []): array
{
    return array_replace([
        'recipient_name' => 'Delivery Recipient',
        'phone' => '+8801712345678',
        'address_line1' => 'House 12, Road 3',
        'city' => 'Dhaka',
        'district' => 'Dhaka',
        'division' => 'Dhaka',
        'postal_code' => '1216',
        'country_code' => 'BD',
    ], $overrides);
}

function customerIntegrationOrder(
    ?Customer $customer,
    array $overrides = [],
): Order {
    return Order::factory()->create([
        'order_number' => 'INT-'.fake()->unique()->numerify('##########'),
        'customer_id' => $customer?->id,
        'shipping_address' => json_encode(
            customerIntegrationDelivery(),
            JSON_THROW_ON_ERROR,
        ),
        'subtotal' => 100,
        'total_amount' => 100,
        ...$overrides,
    ]);
}

it('creates a registered order with the customer identifier and copied delivery address', function () {
    $customer = customerIntegrationAccount();

    $address = Address::factory()->create([
        'customer_id' => $customer->id,
        ...customerIntegrationDelivery(),
        'is_default' => true,
    ]);

    $usersBefore = User::count();
    $customersBefore = Customer::count();
    $receivedIdentity = null;

    $order = app(OrderCustomerService::class)->registered(
        $customer->user,
        $address->id,
        function (array $identity) use ($customer, &$receivedIdentity): Order {
            $receivedIdentity = $identity;

            return customerIntegrationOrder($customer, $identity);
        },
    );

    expect($receivedIdentity)->toBeArray();
    expect($receivedIdentity['customer_id'])->toBe($customer->id);
    expect($order->customer_id)->toBe($customer->id);

    $snapshot = json_decode(
        $order->shipping_address,
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    foreach (customerIntegrationDelivery() as $field => $value) {
        expect($snapshot[$field])->toBe($value);
    }

    expect(User::count())->toBe($usersBefore);
    expect(Customer::count())->toBe($customersBefore);

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'customer_id' => $customer->id,
    ]);
});

it('preserves an existing order delivery snapshot after saved address changes and deletion', function () {
    $customer = customerIntegrationAccount();

    $address = Address::factory()->create([
        'customer_id' => $customer->id,
        'city' => 'Original City',
        'is_default' => true,
    ]);

    $order = app(OrderCustomerService::class)->registered(
        $customer->user,
        $address->id,
        fn (array $identity): Order => customerIntegrationOrder(
            $customer,
            $identity,
        ),
    );

    $snapshot = $order->shipping_address;

    expect(json_decode($snapshot, true)['city'])->toBe('Original City');

    customerIntegrationLogin($this, $customer->user);

    $this->patchJson('/api/customer/addresses/'.$address->id, [
        'city' => 'Updated City',
        'address_line1' => 'Updated Delivery Address',
    ])->assertOk();

    expect($order->fresh()->shipping_address)->toBe($snapshot);

    $this->deleteJson('/api/customer/addresses/'.$address->id)->assertOk();

    expect($order->fresh()->shipping_address)->toBe($snapshot);
});

it('rejects foreign addresses before invoking registered order creation', function () {
    $customer = customerIntegrationAccount();
    $other = customerIntegrationAccount();

    $foreign = Address::factory()->create([
        'customer_id' => $other->id,
        'is_default' => true,
    ]);

    $this->expectException(ModelNotFoundException::class);

    app(OrderCustomerService::class)->registered(
        $customer->user,
        $foreign->id,
        function (): void {
            $this->fail('Foreign address must not reach order creation.');
        },
    );
});

it('rechecks persisted account status instead of trusting a stale customer user object', function () {
    $customer = customerIntegrationAccount();

    $address = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => true,
    ]);

    $staleUser = $customer->user;

    User::query()
        ->whereKey($customer->user_id)
        ->update(['is_active' => false]);

    expect((bool) $staleUser->is_active)->toBeTrue();

    $this->expectException(ValidationException::class);

    app(OrderCustomerService::class)->registered(
        $staleUser,
        $address->id,
        function (): void {
            $this->fail('An inactive account must not reach order creation.');
        },
    );
});

it('rejects direct order creation for inactive customer accounts', function () {
    $customer = customerIntegrationAccount(['is_active' => false]);

    $this->expectException(ValidationException::class);

    customerIntegrationOrder($customer);
});

it('rejects registered checkout and direct order creation after account closure', function () {
    $customer = customerIntegrationAccount();

    $address = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => true,
    ]);

    customerIntegrationStaff($this, ['customers.manage']);

    $this->postJson('/api/customers/'.$customer->id.'/close', [
        'reason' => 'Integration closure check',
    ])->assertOk();

    $serviceRejected = false;

    try {
        app(OrderCustomerService::class)->registered(
            $customer->user,
            $address->id,
            function (): void {
                $this->fail('Closed accounts must not reach order creation.');
            },
        );
    } catch (ValidationException) {
        $serviceRejected = true;
    }

    expect($serviceRejected)->toBeTrue();
    expect(Order::count())->toBe(0);
    Auth::forgetGuards();
    Auth::shouldUse('web');

    $this->expectException(ValidationException::class);

    customerIntegrationOrder($customer);
});

it('creates an unlinked guest order even when contact details match an existing account', function () {
    $customer = customerIntegrationAccount([
        'email' => 'existing@example.test',
        'phone' => '+8801712345678',
    ]);

    $usersBefore = User::count();
    $customersBefore = Customer::count();

    $order = app(OrderCustomerService::class)->guest(
        [
            'name' => 'Guest Customer',
            'email' => 'EXISTING@example.test',
            'phone' => '+8801712345678',
        ],
        customerIntegrationDelivery(),
        fn (array $identity): Order => customerIntegrationOrder(null, $identity),
    );

    expect($order->customer_id)->toBeNull();
    expect($order->guest_name)->toBe('Guest Customer');
    expect($order->guest_email)->toBe('existing@example.test');
    expect($order->guest_phone)->toBe('+8801712345678');

    $snapshot = json_decode(
        $order->shipping_address,
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($snapshot['address_line1'])->toBe('House 12, Road 3');
    expect($snapshot['city'])->toBe('Dhaka');

    expect(User::count())->toBe($usersBefore);
    expect(Customer::count())->toBe($customersBefore);

    $this->getJson('/api/customer/profile')->assertUnauthorized();

    customerIntegrationLogin($this, $customer->user);

    $this->getJson('/api/customer/orders')
        ->assertOk()
        ->assertJsonCount(0, 'data.orders')
        ->assertJsonPath('data.pagination.total', 0);
});

it('creates a guest order without a password email or registered account', function () {
    $usersBefore = User::count();
    $customersBefore = Customer::count();

    $order = app(OrderCustomerService::class)->guest(
        [
            'name' => 'Phone Only Guest',
            'phone' => '+8801812345678',
        ],
        customerIntegrationDelivery(),
        fn (array $identity): Order => customerIntegrationOrder(null, $identity),
    );

    expect($order->customer_id)->toBeNull();
    expect($order->guest_name)->toBe('Phone Only Guest');
    expect($order->guest_phone)->toBe('+8801812345678');
    expect($order->guest_email)->toBeNull();

    expect(User::count())->toBe($usersBefore);
    expect(Customer::count())->toBe($customersBefore);
});

it('rejects guest supplied customer identifiers before order creation', function () {
    $customer = customerIntegrationAccount();

    $this->expectException(ValidationException::class);

    app(OrderCustomerService::class)->guest(
        [
            'name' => 'Unverified Guest',
            'phone' => '+8801712345678',
            'customer_id' => $customer->id,
        ],
        customerIntegrationDelivery(),
        function (): void {
            $this->fail('Guests must not choose an existing customer account.');
        },
    );
});

it('rejects invalid guest delivery data before invoking order creation', function () {
    $this->expectException(ValidationException::class);

    app(OrderCustomerService::class)->guest(
        [
            'name' => 'Guest Customer',
            'phone' => '+8801712345678',
        ],
        customerIntegrationDelivery([
            'phone' => 'invalid',
            'district' => '',
        ]),
        function (): void {
            $this->fail('Invalid delivery data must not reach order creation.');
        },
    );
});

it('prevents an authenticated customer from using guest checkout', function () {
    $customer = customerIntegrationAccount();

    customerIntegrationLogin($this, $customer->user);

    $this->expectException(HttpException::class);

    app(OrderCustomerService::class)->guest(
        [
            'name' => 'Guest Attempt',
            'phone' => '+8801712345678',
        ],
        customerIntegrationDelivery(),
        function (): void {
            $this->fail('Signed-in customers must use registered checkout.');
        },
    );
});

it('rolls back order writes when the registered checkout callback fails', function () {
    $customer = customerIntegrationAccount();

    $address = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => true,
    ]);

    $ordersBefore = Order::count();
    $failure = null;

    try {
        app(OrderCustomerService::class)->registered(
            $customer->user,
            $address->id,
            function (array $identity) use ($customer): void {
                customerIntegrationOrder($customer, $identity);

                throw new RuntimeException('Integration checkout failure');
            },
        );
    } catch (RuntimeException $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(RuntimeException::class);
    expect($failure->getMessage())->toBe('Integration checkout failure');

    expect(Order::count())->toBe($ordersBefore);
    expect($address->fresh())->not->toBeNull();
});

it('scopes paginated order and payment summaries to the authenticated customer', function () {
    $customer = customerIntegrationAccount();
    $other = customerIntegrationAccount();

    $ownOrderIds = [];

    foreach (range(1, 3) as $number) {
        $order = customerIntegrationOrder($customer);
        $ownOrderIds[] = $order->id;

        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'cash',
            'amount' => 100,
            'provider_response' => '{"secret":"private-provider-payload"}',
        ]);
    }

    $foreignOrder = customerIntegrationOrder($other);

    Payment::factory()->create([
        'order_id' => $foreignOrder->id,
        'provider' => 'cash',
        'amount' => 100,
    ]);

    customerIntegrationLogin($this, $customer->user);

    $orderResponse = $this->getJson('/api/customer/orders')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonCount(3, 'data.orders')
        ->assertJsonPath('data.pagination.total', 3)
        ->assertJsonPath('data.pagination.per_page', 15);

    $listedIds = array_column($orderResponse->json('data.orders'), 'id');

    sort($listedIds);
    sort($ownOrderIds);

    expect($listedIds)->toBe($ownOrderIds);

    foreach ($orderResponse->json('data.orders') as $summary) {
        expect(array_key_exists('shipping_address', $summary))->toBeFalse();
    }

    $paymentResponse = $this->getJson('/api/customer/payments')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonCount(3, 'data.payments')
        ->assertJsonPath('data.pagination.total', 3)
        ->assertDontSee('private-provider-payload');

    foreach ($paymentResponse->json('data.payments') as $summary) {
        expect(array_key_exists('provider_response', $summary))->toBeFalse();
    }

    foreach (['orders', 'payments'] as $resource) {
        foreach ([1 => 2, 2 => 1, 3 => 0] as $page => $count) {
            $this->getJson(
                '/api/customer/'.$resource.'?per_page=2&page='.$page,
            )
                ->assertOk()
                ->assertJsonCount($count, 'data.'.$resource)
                ->assertJsonPath('data.pagination.current_page', $page)
                ->assertJsonPath('data.pagination.total', 3)
                ->assertJsonPath('data.pagination.last_page', 2);
        }
    }
});

it('requires both customer view and the relevant domain permission for staff summaries', function () {
    $customer = customerIntegrationAccount();
    $other = customerIntegrationAccount();

    $order = customerIntegrationOrder($customer);
    customerIntegrationOrder($other);

    Payment::factory()->create([
        'order_id' => $order->id,
        'provider' => 'cash',
        'amount' => 100,
    ]);

    $base = '/api/customers/'.$customer->id;

    customerIntegrationStaff($this, ['customers.view']);

    $this->getJson($base.'/orders')->assertForbidden();
    $this->getJson($base.'/payments')->assertForbidden();

    customerIntegrationStaff($this, ['orders.view', 'payments.view']);

    $this->getJson($base.'/orders')->assertForbidden();
    $this->getJson($base.'/payments')->assertForbidden();

    customerIntegrationStaff($this, ['customers.view', 'orders.view']);

    $this->getJson($base.'/orders')
        ->assertOk()
        ->assertJsonCount(1, 'data.orders')
        ->assertJsonPath('data.orders.0.id', $order->id)
        ->assertJsonMissingPath('data.orders.0.shipping_address');

    $this->getJson($base.'/payments')->assertForbidden();

    customerIntegrationStaff($this, ['customers.view', 'payments.view']);

    $this->getJson($base.'/orders')->assertForbidden();

    $this->getJson($base.'/payments')
        ->assertOk()
        ->assertJsonCount(1, 'data.payments')
        ->assertJsonMissingPath('data.payments.0.provider_response');
});
