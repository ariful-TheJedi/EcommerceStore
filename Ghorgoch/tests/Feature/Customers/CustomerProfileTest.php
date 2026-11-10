<?php

declare(strict_types=1);

use App\Auth\Domain\Services\AuthService;
use App\Customers\Models\Customer;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

uses(LazilyRefreshDatabase::class);

function customerProfileAccount(array $fields = []): Customer
{
    $user = User::factory()->create([
        'role' => 'customer',
        ...$fields,
    ]);

    return Customer::factory()
        ->create(['user_id' => $user->id])
        ->load('user');
}

function customerProfileLogin(
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

function customerProfileStaff($test): User
{
    $staff = User::factory()->create([
        'role' => 'staff',
        'permissions' => ['customers.view', 'customers.manage'],
    ]);

    customerProfileLogin($test, $staff, 'staff');

    return $staff;
}

function customerProfileAssertShape(array $profile): void
{
    $expected = [
        'id',
        'name',
        'email',
        'phone',
        'avatar_path',
        'date_of_birth',
        'status',
        'email_verified',
    ];

    $actual = array_keys($profile);

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected);
}

it('returns only the permitted fields from the authenticated customer profile', function () {
    $customer = customerProfileAccount([
        'name' => 'Rahim Customer',
        'email' => 'rahim@example.test',
        'phone' => '+8801712345678',
        'email_verified_at' => now(),
    ]);

    customerProfileLogin($this, $customer->user);

    $response = $this->getJson('/api/customer/profile')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.customer.id', $customer->id)
        ->assertJsonPath('data.customer.name', 'Rahim Customer')
        ->assertJsonPath('data.customer.email', 'rahim@example.test')
        ->assertJsonPath('data.customer.phone', '+8801712345678')
        ->assertJsonPath('data.customer.status', 'active')
        ->assertJsonPath('data.customer.email_verified', true);

    customerProfileAssertShape($response->json('data.customer'));
});

it('updates all permitted own profile fields without changing another customer', function () {
    $customer = customerProfileAccount();
    $other = customerProfileAccount();

    $otherUserBefore = $other->user->fresh()->getRawOriginal();
    $otherProfileBefore = $other->fresh()->getRawOriginal();

    $originalEmail = $customer->user->email;

    customerProfileLogin($this, $customer->user);

    $response = $this->patchJson('/api/customer/profile', [
        'name' => 'Updated Customer',
        'phone' => '+8801812345678',
        'avatar_path' => 'customers/avatars/profile.jpg',
        'date_of_birth' => '1995-04-08',
    ])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.customer.id', $customer->id)
        ->assertJsonPath('data.customer.name', 'Updated Customer')
        ->assertJsonPath('data.customer.phone', '+8801812345678')
        ->assertJsonPath(
            'data.customer.avatar_path',
            'customers/avatars/profile.jpg',
        )
        ->assertJsonPath('data.customer.date_of_birth', '1995-04-08');

    customerProfileAssertShape($response->json('data.customer'));

    $this->assertDatabaseHas('users', [
        'id' => $customer->user_id,
        'name' => 'Updated Customer',
        'phone' => '+8801812345678',
        'email' => $originalEmail,
        'role' => 'customer',
    ]);

    expect($customer->fresh()->date_of_birth->toDateString())
        ->toBe('1995-04-08');

    expect($other->user->fresh()->getRawOriginal())->toBe($otherUserBefore);
    expect($other->fresh()->getRawOriginal())->toBe($otherProfileBefore);

    $this->getJson('/api/customer/profile')
        ->assertOk()
        ->assertJsonPath('data.customer.name', 'Updated Customer');
});

it('preserves omitted fields and allows optional own fields to be cleared', function () {
    $customer = customerProfileAccount([
        'phone' => '+8801712345678',
    ]);

    $customer->user->forceFill(['avatar_path' => 'customers/original.jpg'])->save();
    $customer->forceFill(['date_of_birth' => '1990-01-15'])->save();

    $originalEmail = $customer->user->email;

    customerProfileLogin($this, $customer->user);

    $this->patchJson('/api/customer/profile', ['name' => 'New Name'])
        ->assertOk()
        ->assertJsonPath('data.customer.phone', '+8801712345678')
        ->assertJsonPath('data.customer.avatar_path', 'customers/original.jpg')
        ->assertJsonPath('data.customer.date_of_birth', '1990-01-15');

    $this->patchJson('/api/customer/profile', [
        'phone' => null,
        'avatar_path' => null,
        'date_of_birth' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.customer.name', 'New Name')
        ->assertJsonPath('data.customer.email', $originalEmail)
        ->assertJsonPath('data.customer.phone', null)
        ->assertJsonPath('data.customer.avatar_path', null)
        ->assertJsonPath('data.customer.date_of_birth', null);

    expect($customer->user->fresh()->phone)->toBeNull();
    expect($customer->fresh()->avatar_path)->toBeNull();
    expect($customer->fresh()->date_of_birth)->toBeNull();
});

it('rejects protected and unexpected own profile fields atomically', function () {
    $customer = customerProfileAccount();
    $other = customerProfileAccount();

    $userBefore = $customer->user->fresh()->getRawOriginal();
    $profileBefore = $customer->fresh()->getRawOriginal();

    customerProfileLogin($this, $customer->user);

    $forbidden = [
        'id' => $other->id,
        'user_id' => $other->user_id,
        'customer_id' => $other->id,
        'email' => 'replacement@example.test',
        'role' => 'staff',
        'permissions' => ['customers.manage'],
        'is_active' => false,
        'status' => 'blocked',
        'payment_status' => 'paid',
        'password' => 'InjectedPassword@123',
        'email_verified_at' => now()->toISOString(),
        'email_verified' => true,
        'unexpected_field' => 'unexpected',
    ];

    $this->patchJson('/api/customer/profile', [
        'name' => 'Must Not Persist',
        ...$forbidden,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(array_keys($forbidden));

    expect($customer->user->fresh()->getRawOriginal())->toBe($userBefore);
    expect($customer->fresh()->getRawOriginal())->toBe($profileBefore);
});

dataset('customers.profile.invalid_fields', [
    'blank name' => ['name', '   '],
    'name too long' => ['name', str_repeat('N', 151)],
    'local phone format' => ['phone', '01712345678'],
    'invalid mobile prefix' => ['phone', '+8801212345678'],
    'phone too short' => ['phone', '+880171234567'],
    'avatar path too long' => ['avatar_path', str_repeat('a', 501)],
    'invalid birth date' => ['date_of_birth', 'not-a-date'],
    'impossible birth date' => ['date_of_birth', '1995-02-30'],
]);

it('rejects invalid own profile input without persisting other changes', function (
    string $field,
    mixed $value,
) {
    $customer = customerProfileAccount();

    $userBefore = $customer->user->fresh()->getRawOriginal();
    $profileBefore = $customer->fresh()->getRawOriginal();

    customerProfileLogin($this, $customer->user);

    $this->patchJson('/api/customer/profile', [
        'name' => 'Must Not Persist',
        $field => $value,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect($customer->user->fresh()->getRawOriginal())->toBe($userBefore);
    expect($customer->fresh()->getRawOriginal())->toBe($profileBefore);
})->with('customers.profile.invalid_fields');

it('rejects a future birth date and accepts today', function () {
    $customer = customerProfileAccount();

    customerProfileLogin($this, $customer->user);

    $this->patchJson('/api/customer/profile', [
        'date_of_birth' => now()->addDay()->toDateString(),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date_of_birth');

    $today = now()->toDateString();

    $this->patchJson('/api/customer/profile', [
        'date_of_birth' => $today,
    ])
        ->assertOk()
        ->assertJsonPath('data.customer.date_of_birth', $today);
});

it('enforces phone uniqueness across customers and staff while allowing the current phone', function () {
    $customer = customerProfileAccount([
        'phone' => '+8801712345678',
    ]);

    customerProfileAccount(['phone' => '+8801812345678']);

    User::factory()->create([
        'role' => 'staff',
        'phone' => '+8801912345678',
    ]);

    customerProfileLogin($this, $customer->user);

    foreach (['+8801812345678', '+8801912345678'] as $phone) {
        $this->patchJson('/api/customer/profile', [
            'name' => 'Must Not Persist',
            'phone' => $phone,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    expect($customer->user->fresh()->name)->toBe($customer->user->name);
    expect($customer->user->fresh()->phone)->toBe('+8801712345678');

    $this->patchJson('/api/customer/profile', [
        'phone' => '+8801712345678',
    ])
        ->assertOk()
        ->assertJsonPath('data.customer.phone', '+8801712345678');
});

it('creates a customer through staff administration without exposing credentials or switching identity', function () {
    $staff = customerProfileStaff($this);

    $usersBefore = User::count();
    $customersBefore = Customer::count();

    $response = $this->postJson('/api/customers', [
        'name' => 'Created Customer',
        'email' => '  CREATED@Example.Test ',
        'phone' => '+8801712345678',
        'avatar_path' => 'customers/created.jpg',
        'date_of_birth' => '1992-01-01',
    ])
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.customer.email', 'created@example.test')
        ->assertJsonPath('data.customer.name', 'Created Customer')
        ->assertJsonPath('data.customer.status', 'active')
        ->assertJsonPath('data.customer.email_verified', false)
        ->assertJsonMissingPath('password')
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.customer.password');

    customerProfileAssertShape($response->json('data.customer'));

    $created = Customer::query()
        ->findOrFail($response->json('data.customer.id'))
        ->load('user');

    expect(User::count())->toBe($usersBefore + 1);
    expect(Customer::count())->toBe($customersBefore + 1);
    expect($created->user->role)->toBe('customer');
    expect((bool) $created->user->is_active)->toBeTrue();
    expect($created->user->email_verified_at)->toBeNull();
    expect($created->user->getRawOriginal('password'))->toBeString()->not->toBeEmpty();

    // The stored password must be a valid password hash.
    expect(Hash::info($created->user->getRawOriginal('password'))['algoName'])
        ->not->toBe('unknown');

    expect(Auth::guard('staff')->id())->toBe($staff->id);
    expect(Auth::guard('customer')->check())->toBeFalse();

    $this->getJson('/api/customers/'.$created->id)
        ->assertOk()
        ->assertJsonPath('data.customer.id', $created->id);
});

it('requires name and email when staff creates a customer', function () {
    customerProfileStaff($this);

    $usersBefore = User::count();
    $customersBefore = Customer::count();

    $this->postJson('/api/customers', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email']);

    expect(User::count())->toBe($usersBefore);
    expect(Customer::count())->toBe($customersBefore);
});

it('allows staff to read and partially update the selected customer', function () {
    $customer = customerProfileAccount([
        'name' => 'Original Name',
        'email' => 'original@example.test',
        'phone' => '+8801712345678',
    ]);

    $other = customerProfileAccount();
    $otherUserBefore = $other->user->fresh()->getRawOriginal();

    customerProfileStaff($this);

    $path = '/api/customers/'.$customer->id;

    $response = $this->getJson($path)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.customer.id', $customer->id);

    customerProfileAssertShape($response->json('data.customer'));

    $this->patchJson($path, [
        'name' => 'Staff Updated Name',
        'avatar_path' => 'customers/staff-updated.jpg',
        'date_of_birth' => '1991-12-05',
    ])
        ->assertOk()
        ->assertJsonPath('data.customer.name', 'Staff Updated Name')
        ->assertJsonPath('data.customer.email', 'original@example.test')
        ->assertJsonPath('data.customer.phone', '+8801712345678')
        ->assertJsonPath('data.customer.date_of_birth', '1991-12-05')
        ->assertJsonPath('data.customer.status', 'active');

    $this->assertDatabaseHas('users', [
        'id' => $customer->user_id,
        'name' => 'Staff Updated Name',
        'role' => 'customer',
    ]);

    expect($other->user->fresh()->getRawOriginal())->toBe($otherUserBefore);

    $this->patchJson($path, [
        'phone' => null,
        'avatar_path' => null,
        'date_of_birth' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.customer.phone', null)
        ->assertJsonPath('data.customer.avatar_path', null)
        ->assertJsonPath('data.customer.date_of_birth', null);
});

it('rejects invalid staff profile updates atomically', function () {
    $customer = customerProfileAccount();

    customerProfileStaff($this);

    $userBefore = $customer->user->fresh()->getRawOriginal();
    $profileBefore = $customer->fresh()->getRawOriginal();

    $this->patchJson('/api/customers/'.$customer->id, [
        'name' => 'Must Not Persist',
        'email' => 'invalid-email',
        'phone' => '01712345678',
        'avatar_path' => str_repeat('a', 501),
        'date_of_birth' => now()->addDay()->toDateString(),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'email',
            'phone',
            'avatar_path',
            'date_of_birth',
        ]);

    expect($customer->user->fresh()->getRawOriginal())->toBe($userBefore);
    expect($customer->fresh()->getRawOriginal())->toBe($profileBefore);
});

it('rejects authority credentials and account identifiers in staff create and update requests', function () {
    $customer = customerProfileAccount();

    customerProfileStaff($this);

    $usersBefore = User::count();
    $customersBefore = Customer::count();

    $userBefore = $customer->user->fresh()->getRawOriginal();
    $profileBefore = $customer->fresh()->getRawOriginal();

    $forbidden = [
        'id' => 999999,
        'user_id' => 999999,
        'customer_id' => 999999,
        'role' => 'staff',
        'permissions' => ['customers.manage'],
        'is_active' => false,
        'status' => 'blocked',
        'payment_status' => 'paid',
        'password' => 'InjectedPassword@123',
        'email_verified_at' => now()->toISOString(),
        'email_verified' => true,
        'unexpected_field' => 'unexpected',
    ];

    $this->postJson('/api/customers', [
        'name' => 'Must Not Be Created',
        'email' => 'forbidden-create@example.test',
        ...$forbidden,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(array_keys($forbidden));

    $this->patchJson('/api/customers/'.$customer->id, [
        'name' => 'Must Not Persist',
        ...$forbidden,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(array_keys($forbidden));

    expect(User::count())->toBe($usersBefore);
    expect(Customer::count())->toBe($customersBefore);
    expect($customer->user->fresh()->getRawOriginal())->toBe($userBefore);
    expect($customer->fresh()->getRawOriginal())->toBe($profileBefore);
});

it('enforces global email and phone uniqueness for staff creation and updates', function () {
    $owner = customerProfileAccount([
        'email' => 'taken@example.test',
        'phone' => '+8801712345678',
    ]);

    $target = customerProfileAccount();

    User::factory()->create([
        'role' => 'staff',
        'email' => 'staff-taken@example.test',
        'phone' => '+8801812345678',
    ]);

    customerProfileStaff($this);

    $usersBefore = User::count();
    $customersBefore = Customer::count();
    $targetBefore = $target->user->fresh()->getRawOriginal();

    foreach ([
        ['TAKEN@EXAMPLE.TEST', '+8801712345678'],
        ['STAFF-TAKEN@EXAMPLE.TEST', '+8801812345678'],
    ] as [$email, $phone]) {
        $payload = [
            'name' => 'Must Not Persist',
            'email' => $email,
            'phone' => $phone,
        ];

        $this->postJson('/api/customers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'phone']);

        $this->patchJson('/api/customers/'.$target->id, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'phone']);
    }

    expect(User::count())->toBe($usersBefore);
    expect(Customer::count())->toBe($customersBefore);
    expect($target->user->fresh()->getRawOriginal())->toBe($targetBefore);

    $this->patchJson('/api/customers/'.$owner->id, [
        'email' => '  TAKEN@EXAMPLE.TEST ',
        'phone' => '+8801712345678',
    ])
        ->assertOk()
        ->assertJsonPath('data.customer.email', 'taken@example.test')
        ->assertJsonPath('data.customer.phone', '+8801712345678');
});

it('clears email verification and invalidates the previous fingerprint after a staff email change', function () {
    $customer = customerProfileAccount([
        'email' => 'verified@example.test',
        'email_verified_at' => now(),
    ]);

    $oldFingerprint = app(AuthService::class)->fingerprint($customer->user);

    customerProfileStaff($this);

    $this->patchJson('/api/customers/'.$customer->id, [
        'email' => '  NEW@Example.Test ',
    ])
        ->assertOk()
        ->assertJsonPath('data.customer.email', 'new@example.test')
        ->assertJsonPath('data.customer.email_verified', false);

    $freshUser = $customer->user->fresh();

    expect($freshUser->email)->toBe('new@example.test');
    expect($freshUser->email_verified_at)->toBeNull();
    expect(app(AuthService::class)->fingerprint($freshUser))
        ->not->toBe($oldFingerprint);

    // Reuse the old authentication fingerprint to simulate stale access.
    Auth::forgetGuards();

    $this->actingAs($freshUser, 'customer')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => $oldFingerprint,
    ]);

    $this->getJson('/api/customer/profile')->assertUnauthorized();
});

it('returns not found for missing customer profiles without creating records', function () {
    customerProfileStaff($this);

    $missingId = ((int) Customer::max('id')) + 1000;

    $usersBefore = User::count();
    $customersBefore = Customer::count();

    $this->getJson('/api/customers/'.$missingId)->assertNotFound();

    $this->patchJson('/api/customers/'.$missingId, [
        'name' => 'Missing Customer',
    ])->assertNotFound();

    expect(User::count())->toBe($usersBefore);
    expect(Customer::count())->toBe($customersBefore);
});
