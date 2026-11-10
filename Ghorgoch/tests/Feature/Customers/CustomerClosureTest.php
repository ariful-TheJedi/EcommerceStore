<?php

declare(strict_types=1);

use App\Auth\Domain\Services\AuthService;
use App\Customers\Models\Address;
use App\Customers\Models\Customer;
use App\Orders\Models\Order;
use App\Payments\Models\Payment;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

function customerClosureAccount(array $fields = []): Customer
{
    $user = User::factory()->create([
        'role' => 'customer',
        'password' => 'CorrectPassword@123',
        ...$fields,
    ]);

    return Customer::factory()
        ->create(['user_id' => $user->id])
        ->load('user');
}

function customerClosureLogin(
    $test,
    User $user,
    string $guard = 'customer',
    ?string $fingerprint = null,
): void {
    Auth::forgetGuards();

    $test->actingAs($user, $guard)->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => $fingerprint
            ?? app(AuthService::class)->fingerprint($user),
    ]);
}

function customerClosureStaff(
    $test,
    array $permissions = [
        'customers.view',
        'customers.manage',
        'customers.notes',
    ],
): User {
    $staff = User::factory()->create([
        'role' => 'staff',
        'permissions' => $permissions,
    ]);

    customerClosureLogin($test, $staff, 'staff');

    return $staff;
}

function customerClosureOrder(Customer $customer): Order
{
    return Order::factory()->create([
        'order_number' => 'CLOSE-'.fake()->unique()->numerify('##########'),
        'customer_id' => $customer->id,
        'shipping_address' => json_encode([
            'recipient_name' => 'Original Delivery Recipient',
            'phone' => '+8801712345678',
            'address_line1' => 'Original Delivery Address',
            'city' => 'Dhaka',
            'district' => 'Dhaka',
            'division' => 'Dhaka',
            'country_code' => 'BD',
        ], JSON_THROW_ON_ERROR),
        'subtotal' => 100,
        'total_amount' => 100,
    ]);
}

dataset('customers.closure.actors', [
    'customer closes own account' => ['customer'],
    'staff closes selected account' => ['staff'],
]);

it('anonymizes the account and removes saved personal data while preserving transactions', function (
    string $actor,
) {
    $customer = customerClosureAccount([
        'name' => 'Private Customer Name',
        'email' => 'closure-private@example.test',
        'phone' => '+8801712345678',
        'email_verified_at' => now(),
    ]);

    $customer->user->forceFill(['avatar_path' => 'customers/private-avatar.jpg'])->save();
    $customer->forceFill(['date_of_birth' => '1990-03-15'])->save();

    Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => true,
    ]);

    $order = customerClosureOrder($customer);

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'provider' => 'cash',
        'amount' => 100,
    ]);

    $orderBefore = $order->fresh()->getRawOriginal();
    $paymentBefore = $payment->fresh()->getRawOriginal();

    $oldEmail = $customer->user->email;
    $oldFingerprint = app(AuthService::class)->fingerprint($customer->user);

    $usersBefore = User::count();
    $customersBefore = Customer::count();

    if ($actor === 'customer') {
        customerClosureLogin($this, $customer->user);

        $response = $this->postJson('/api/customer/close', [
            'current_password' => 'CorrectPassword@123',
        ]);
    } else {
        $staff = customerClosureStaff($this);
        $usersBefore++;

        $response = $this->postJson(
            '/api/customers/'.$customer->id.'/close',
            ['reason' => 'Customer requested account closure'],
        );

        $this->assertDatabaseHas('customer_status_history', [
            'customer_id' => $customer->id,
            'actor_id' => $staff->id,
            'action' => 'closed',
        ]);
    }

    $response
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    $freshCustomer = $customer->fresh();
    $freshUser = $customer->user->fresh();

    expect(User::count())->toBe($usersBefore);
    expect(Customer::count())->toBe($customersBefore);

    expect($freshCustomer->id)->toBe($customer->id);
    expect($freshCustomer->user_id)->toBe($customer->user_id);
    expect($freshCustomer->closed_at)->not->toBeNull();

    expect($freshUser->name)->toBe('Closed customer');
    expect($freshUser->email)->not->toBe($oldEmail);
    expect($freshUser->phone)->toBeNull();
    expect((bool) $freshUser->is_active)->toBeFalse();
    expect($freshUser->role)->toBe('customer');

    expect($freshCustomer->user->avatar_path)->toBeNull();
    expect($freshCustomer->date_of_birth)->toBeNull();
    expect($customer->addresses()->count())->toBe(0);

    expect(app(AuthService::class)->fingerprint($freshUser))
        ->not->toBe($oldFingerprint);

    expect($order->fresh()->getRawOriginal())->toBe($orderBefore);
    expect($payment->fresh()->getRawOriginal())->toBe($paymentBefore);

    customerClosureLogin($this, $freshUser, 'customer', $oldFingerprint);

    $this->getJson('/api/customer/profile')->assertUnauthorized();

    // A closed account remains inaccessible even with its current fingerprint.
    customerClosureLogin($this, $freshUser);

    $this->getJson('/api/customer/profile')->assertUnauthorized();
})->with('customers.closure.actors');

it('requires the correct current password before self closure and leaves data unchanged on failure', function () {
    $customer = customerClosureAccount();

    $address = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => true,
    ]);

    $userBefore = $customer->user->fresh()->getRawOriginal();
    $profileBefore = $customer->fresh()->getRawOriginal();
    $addressBefore = $address->fresh()->getRawOriginal();

    $historyBefore = DB::table('customer_status_history')
        ->where('customer_id', $customer->id)
        ->count();

    customerClosureLogin($this, $customer->user);

    foreach ([
        [],
        ['current_password' => ''],
        ['current_password' => 'WrongPassword@123'],
    ] as $payload) {
        $this->postJson('/api/customer/close', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        expect($customer->user->fresh()->getRawOriginal())->toBe($userBefore);
        expect($customer->fresh()->getRawOriginal())->toBe($profileBefore);
        expect($address->fresh()->getRawOriginal())->toBe($addressBefore);

        expect(
            DB::table('customer_status_history')
                ->where('customer_id', $customer->id)
                ->count(),
        )->toBe($historyBefore);
    }

    $this->getJson('/api/customer/profile')->assertOk();
});

it('requires a valid reason before staff closure without altering the customer', function () {
    $customer = customerClosureAccount();

    customerClosureStaff($this);

    $userBefore = $customer->user->fresh()->getRawOriginal();
    $profileBefore = $customer->fresh()->getRawOriginal();

    $historyBefore = DB::table('customer_status_history')
        ->where('customer_id', $customer->id)
        ->count();

    foreach ([
        [],
        ['reason' => '   '],
        ['reason' => str_repeat('R', 1001)],
    ] as $payload) {
        $this->postJson('/api/customers/'.$customer->id.'/close', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        expect($customer->user->fresh()->getRawOriginal())->toBe($userBefore);
        expect($customer->fresh()->getRawOriginal())->toBe($profileBefore);

        expect(
            DB::table('customer_status_history')
                ->where('customer_id', $customer->id)
                ->count(),
        )->toBe($historyBefore);
    }
});

it('accepts a staff closure reason at the maximum supported length', function () {
    $customer = customerClosureAccount();
    $staff = customerClosureStaff($this);

    $this->postJson('/api/customers/'.$customer->id.'/close', [
        'reason' => str_repeat('R', 1000),
    ])->assertOk();

    expect($customer->fresh()->closed_at)->not->toBeNull();

    $this->assertDatabaseHas('customer_status_history', [
        'customer_id' => $customer->id,
        'actor_id' => $staff->id,
        'action' => 'closed',
    ]);
});

it('removes internal notes and scrubs previous status reasons during closure', function () {
    $customer = customerClosureAccount();

    customerClosureStaff($this);

    $base = '/api/customers/'.$customer->id;

    $privateReason = 'Sensitive personal details in the previous block reason';
    $privateNote = 'Sensitive internal customer observation';

    $this->postJson($base.'/notes', [
        'body' => $privateNote,
    ])->assertCreated();

    $this->postJson($base.'/block', [
        'reason' => $privateReason,
    ])->assertOk();

    $this->assertDatabaseHas('customer_notes', [
        'customer_id' => $customer->id,
        'body' => $privateNote,
    ]);

    $this->assertDatabaseHas('customer_status_history', [
        'customer_id' => $customer->id,
        'action' => 'blocked',
        'reason' => $privateReason,
    ]);

    $this->postJson($base.'/close', [
        'reason' => 'Account closure requested',
    ])->assertOk();

    expect(
        DB::table('customer_notes')
            ->where('customer_id', $customer->id)
            ->count(),
    )->toBe(0);

    expect($customer->fresh()->blocked_reason)->not->toBe($privateReason);

    expect(
        DB::table('customer_status_history')
            ->where('customer_id', $customer->id)
            ->where('reason', $privateReason)
            ->exists(),
    )->toBeFalse();

    // Status actions remain available for audit after personal reasons are scrubbed.
    $this->assertDatabaseHas('customer_status_history', [
        'customer_id' => $customer->id,
        'action' => 'blocked',
    ]);

    $this->assertDatabaseHas('customer_status_history', [
        'customer_id' => $customer->id,
        'action' => 'closed',
    ]);
});

it('does not allow a closed account to be reopened or assigned new addresses', function () {
    $customer = customerClosureAccount();

    customerClosureStaff($this);

    $base = '/api/customers/'.$customer->id;

    $this->postJson($base.'/close', [
        'reason' => 'Permanent account closure',
    ])->assertOk();

    $userBefore = $customer->user->fresh()->getRawOriginal();
    $profileBefore = $customer->fresh()->getRawOriginal();

    $this->patchJson($base, [
        'name' => 'Attempted Reopening',
    ])->assertConflict();

    $this->postJson($base.'/unblock', [
        'reason' => 'Attempted reopening',
    ])->assertConflict();

    $this->postJson($base.'/addresses', [
        'recipient_name' => 'New Recipient',
        'phone' => '+8801712345678',
        'address_line1' => 'House 12',
        'city' => 'Dhaka',
        'district' => 'Dhaka',
        'division' => 'Dhaka',
    ])->assertConflict();

    expect($customer->user->fresh()->getRawOriginal())->toBe($userBefore);
    expect($customer->fresh()->getRawOriginal())->toBe($profileBefore);
    expect($customer->addresses()->count())->toBe(0);
});

it('requires customer manage permission for staff closure', function () {
    $customer = customerClosureAccount();

    customerClosureStaff($this, ['customers.view']);

    $this->postJson('/api/customers/'.$customer->id.'/close', [
        'reason' => 'Unauthorized closure',
    ])->assertForbidden();

    expect($customer->fresh()->closed_at)->toBeNull();
    expect((bool) $customer->user->fresh()->is_active)->toBeTrue();
});

it('closes only the authenticated customer and preserves another customers profile and address', function () {
    $customer = customerClosureAccount();
    $other = customerClosureAccount();

    $otherAddress = Address::factory()->create([
        'customer_id' => $other->id,
        'is_default' => true,
    ]);

    $otherUserBefore = $other->user->fresh()->getRawOriginal();
    $otherProfileBefore = $other->fresh()->getRawOriginal();
    $otherAddressBefore = $otherAddress->fresh()->getRawOriginal();

    customerClosureLogin($this, $customer->user);

    $this->postJson('/api/customer/close', [
        'current_password' => 'CorrectPassword@123',
    ])->assertOk();

    expect($customer->fresh()->closed_at)->not->toBeNull();

    expect($other->user->fresh()->getRawOriginal())->toBe($otherUserBefore);
    expect($other->fresh()->getRawOriginal())->toBe($otherProfileBefore);
    expect($otherAddress->fresh()->getRawOriginal())->toBe($otherAddressBefore);

    customerClosureLogin($this, $other->user->fresh());

    $this->getJson('/api/customer/profile')
        ->assertOk()
        ->assertJsonPath('data.customer.id', $other->id);
});

it('returns not found when staff attempts to close a missing customer', function () {
    customerClosureStaff($this);

    $missingId = ((int) Customer::max('id')) + 1000;

    $usersBefore = User::count();
    $customersBefore = Customer::count();

    $this->postJson('/api/customers/'.$missingId.'/close', [
        'reason' => 'Missing customer',
    ])->assertNotFound();

    expect(User::count())->toBe($usersBefore);
    expect(Customer::count())->toBe($customersBefore);
});
