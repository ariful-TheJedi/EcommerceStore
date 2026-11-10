<?php

declare(strict_types=1);

use App\Auth\Domain\Services\AuthService;
use App\Customers\Models\Customer;
use App\Orders\Domain\Services\OrderCustomerService;
use App\Orders\Models\Order;
use App\Payments\Models\Payment;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

function customersAutoFlowLogin(
    $test,
    User $user,
    string $guard,
    ?string $fingerprint = null,
): void {
    Auth::forgetGuards();

    $test->actingAs($user, $guard)->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => $fingerprint
            ?? app(AuthService::class)->fingerprint($user),
    ]);
}

function customersAutoFlowAddress(array $overrides = []): array
{
    return array_replace([
        'recipient_name' => 'Lifecycle Customer',
        'phone' => '+8801712345678',
        'address_line1' => 'House 12, Road 3',
        'city' => 'Dhaka',
        'district' => 'Dhaka',
        'division' => 'Dhaka',
        'postal_code' => '1216',
        'country_code' => 'BD',
        'label' => 'Home',
    ], $overrides);
}

it('completes customer creation profile updates delivery selection status review and permanent closure', function () {
    $staff = User::factory()->create([
        'role' => 'staff',
        'permissions' => [
            'customers.view',
            'customers.manage',
            'customers.notes',
            'orders.view',
            'payments.view',
        ],
    ]);

    customersAutoFlowLogin($this, $staff, 'staff');

    // Staff creates a customer without choosing or receiving a password.
    $customerId = $this->postJson('/api/customers', [
        'name' => 'Lifecycle Customer',
        'email' => '  LIFECYCLE@Example.Test ',
        'phone' => '+8801712345678',
    ])
        ->assertCreated()
        ->assertJsonPath('data.customer.email', 'lifecycle@example.test')
        ->assertJsonPath('data.customer.status', 'active')
        ->assertJsonMissingPath('data.customer.password')
        ->json('data.customer.id');

    $customer = Customer::query()
        ->findOrFail($customerId)
        ->load('user');

    $staffBase = '/api/customers/'.$customerId;

    expect($customer->user->role)->toBe('customer');

    $this->getJson('/api/customers?'.http_build_query([
        'search' => 'lifecycle@example.test',
    ]))
        ->assertOk()
        ->assertJsonCount(1, 'data.customers')
        ->assertJsonPath('data.customers.0.id', $customerId);

    // Use the same authenticated guard setup as the existing module tests.
    customersAutoFlowLogin($this, $customer->user, 'customer');

    $this->patchJson('/api/customer/profile', [
        'name' => 'Updated Lifecycle Customer',
        'date_of_birth' => '1995-04-08',
    ])
        ->assertOk()
        ->assertJsonPath('data.customer.name', 'Updated Lifecycle Customer')
        ->assertJsonPath('data.customer.date_of_birth', '1995-04-08');

    $homeId = $this->postJson(
        '/api/customer/addresses',
        customersAutoFlowAddress(['is_default' => false]),
    )
        ->assertCreated()
        ->assertJsonPath('data.address.is_default', true)
        ->json('data.address.id');

    $officeId = $this->postJson(
        '/api/customer/addresses',
        customersAutoFlowAddress([
            'label' => 'Office',
            'address_line1' => 'Office Tower, Floor 4',
            'city' => 'Gazipur',
        ]),
    )
        ->assertCreated()
        ->assertJsonPath('data.address.is_default', false)
        ->json('data.address.id');

    $this->patchJson('/api/customer/addresses/'.$officeId, [
        'is_default' => true,
    ])
        ->assertOk()
        ->assertJsonPath('data.address.is_default', true);

    $this->assertDatabaseHas('addresses', [
        'id' => $homeId,
        'is_default' => false,
    ]);

    expect($customer->addresses()->where('is_default', true)->count())
        ->toBe(1);

    // Exercise the Orders service boundary; this is not a public checkout flow.
    $order = app(OrderCustomerService::class)->registered(
        $customer->user->fresh(),
        $officeId,
        fn (array $identity): Order => Order::factory()->create([
            'order_number' => 'FLOW-'.fake()->unique()->numerify('##########'),
            'subtotal' => 100,
            'total_amount' => 100,
            ...$identity,
        ]),
    );

    expect($order->customer_id)->toBe($customerId);

    $snapshot = $order->shipping_address;
    $shipping = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);

    expect($shipping['city'])->toBe('Gazipur');
    expect($shipping['address_line1'])->toBe('Office Tower, Floor 4');

    // Seed a payment to exercise summary access and retention.
    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'provider' => 'cash',
        'amount' => 100,
        'provider_response' => '{"secret":"lifecycle-provider-secret"}',
    ]);

    $paymentBefore = $payment->fresh()->getRawOriginal();

    $this->getJson('/api/customer/orders')
        ->assertOk()
        ->assertJsonCount(1, 'data.orders')
        ->assertJsonPath('data.orders.0.id', $order->id)
        ->assertJsonMissingPath('data.orders.0.shipping_address');

    $this->getJson('/api/customer/payments')
        ->assertOk()
        ->assertJsonCount(1, 'data.payments')
        ->assertDontSee('lifecycle-provider-secret');

    $oldFingerprint = app(AuthService::class)
        ->fingerprint($customer->user->fresh());

    customersAutoFlowLogin($this, $staff, 'staff');

    $note = 'Internal lifecycle review note';

    $this->postJson($staffBase.'/notes', ['body' => $note])
        ->assertCreated();

    $this->getJson($staffBase.'/notes')
        ->assertOk()
        ->assertJsonPath('data.notes.0.body', $note);

    $this->getJson($staffBase)
        ->assertOk()
        ->assertDontSee($note);

    $this->postJson($staffBase.'/block', [
        'reason' => 'Delivery issue under review',
    ])
        ->assertOk()
        ->assertJsonPath('data.customer.status', 'blocked');

    expect((bool) $customer->user->fresh()->is_active)->toBeFalse();

    customersAutoFlowLogin(
        $this,
        $customer->user->fresh(),
        'customer',
        $oldFingerprint,
    );

    $this->getJson('/api/customer/profile')->assertUnauthorized();

    customersAutoFlowLogin($this, $staff, 'staff');

    $this->postJson($staffBase.'/unblock', [
        'reason' => 'Delivery review completed',
    ])
        ->assertOk()
        ->assertJsonPath('data.customer.status', 'active');

    $this->getJson($staffBase.'/status-history')
        ->assertOk()
        ->assertJsonCount(2, 'data.history');

    // Unblocking must not revive access using the original fingerprint.
    customersAutoFlowLogin(
        $this,
        $customer->user->fresh(),
        'customer',
        $oldFingerprint,
    );

    $this->getJson('/api/customer/profile')->assertUnauthorized();

    customersAutoFlowLogin($this, $customer->user->fresh(), 'customer');

    $this->getJson('/api/customer/profile')
        ->assertOk()
        ->assertJsonPath('data.customer.status', 'active');

    $this->patchJson('/api/customer/addresses/'.$officeId, [
        'city' => 'Changed After Order',
    ])->assertOk();

    $this->deleteJson('/api/customer/addresses/'.$officeId)->assertOk();

    $this->getJson('/api/customer/addresses/'.$homeId)
        ->assertOk()
        ->assertJsonPath('data.address.is_default', true);

    expect($order->fresh()->shipping_address)->toBe($snapshot);

    customersAutoFlowLogin($this, $staff, 'staff');

    $this->getJson($staffBase.'/orders')
        ->assertOk()
        ->assertJsonPath('data.orders.0.id', $order->id);

    $this->getJson($staffBase.'/payments')
        ->assertOk()
        ->assertJsonCount(1, 'data.payments')
        ->assertDontSee('lifecycle-provider-secret');

    $this->postJson($staffBase.'/close', [
        'reason' => 'Customer requested permanent closure',
    ])->assertOk();

    $freshCustomer = $customer->fresh();
    $freshUser = $customer->user->fresh();

    expect($freshCustomer->closed_at)->not->toBeNull();
    expect($freshUser->name)->toBe('Closed customer');
    expect($freshUser->email)->not->toBe('lifecycle@example.test');
    expect($freshUser->phone)->toBeNull();
    expect((bool) $freshUser->is_active)->toBeFalse();

    expect($customer->addresses()->count())->toBe(0);

    expect(
        DB::table('customer_notes')
            ->where('customer_id', $customerId)
            ->count(),
    )->toBe(0);

    expect($order->fresh()->customer_id)->toBe($customerId);
    expect($order->fresh()->shipping_address)->toBe($snapshot);
    expect($payment->fresh()->getRawOriginal())->toBe($paymentBefore);

    $this->patchJson($staffBase, ['name' => 'Attempted Reopening'])
        ->assertConflict();

    $this->postJson($staffBase.'/unblock', [
        'reason' => 'Attempted reopening',
    ])->assertConflict();

    customersAutoFlowLogin($this, $freshUser, 'customer');

    $this->getJson('/api/customer/profile')->assertUnauthorized();
});
