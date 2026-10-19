<?php

use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    expect(app()->environment())->toBe('testing');
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');

    config([
        'auth.customer_enabled' => true,
        'auth.email_enabled' => false,
        'session.driver' => 'database',
        'session.lottery' => [0, 100],
    ]);

    Notification::fake();
});

function customerLoginAccount(array $overrides = []): User
{
    $user = User::factory()->create(array_replace([
        'email' => 'login-customer@example.test',
        'password' => Hash::make('CustomerPassword@123'),
        'role' => 'customer',
        'is_active' => true,
        'email_verified_at' => now(),
    ], $overrides));

    DB::table('customers')->insert([
        'user_id' => $user->id,
    ]);

    return $user;
}

function customerLoginPayload(array $overrides = []): array
{
    return array_replace([
        'email' => 'login-customer@example.test',
        'password' => 'CustomerPassword@123',
    ], $overrides);
}

function assertCustomerLoginValidationRejected(
    $test,
    array $payload,
    string $field
): void {
    $test->postJson('/api/auth/customer/login', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    // Rejected input must not establish an authenticated session.
    $test->getJson('/api/auth/customer/me')
        ->assertUnauthorized();
}

it('logs in an active verified customer', function () {
    $user = customerLoginAccount();

    $response = $this->postJson(
        '/api/auth/customer/login',
        customerLoginPayload()
    );

    $response
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.user.role', 'customer')
        ->assertJsonStructure([
            'data' => [
                'user',
                'csrf_token',
            ],
        ]);

    $token = $response->json('data.csrf_token');

    expect($token)->toBeString();
    expect($token)->not->toBe('');
    expect($token)->toBe(session()->token());
});

it('allows an active unverified customer to log in', function () {
    $user = customerLoginAccount([
        'email_verified_at' => null,
    ]);

    $this->postJson(
        '/api/auth/customer/login',
        customerLoginPayload()
    )->assertOk();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('allows customer login while email features are disabled', function () {
    config(['auth.email_enabled' => false]);

    customerLoginAccount(['email_verified_at' => null]);

    $this->postJson(
        '/api/auth/customer/login',
        customerLoginPayload()
    )->assertOk();

    Notification::assertNothingSent();
});

it('establishes access to the matching customer session', function () {
    $user = customerLoginAccount();

    $this->postJson(
        '/api/auth/customer/login',
        customerLoginPayload()
    )->assertOk();

    $this->getJson('/api/auth/customer/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email);
});

it('does not grant staff access after customer login', function () {
    customerLoginAccount();

    $this->postJson(
        '/api/auth/customer/login',
        customerLoginPayload()
    )->assertOk();

    $this->getJson('/api/auth/staff/me')
        ->assertUnauthorized();
});

it('rejects an unknown email without creating an account', function () {
    $usersBefore = DB::table('users')->count();
    $customersBefore = DB::table('customers')->count();

    $this->postJson(
        '/api/auth/customer/login',
        customerLoginPayload()
    )->assertUnauthorized();

    expect(DB::table('users')->count())->toBe($usersBefore);
    expect(DB::table('customers')->count())->toBe($customersBefore);

    $this->getJson('/api/auth/customer/me')
        ->assertUnauthorized();
});

it('rejects a wrong password without changing the stored password', function () {
    $user = customerLoginAccount();
    $originalHash = $user->password;

    $this->postJson(
        '/api/auth/customer/login',
        customerLoginPayload([
            'password' => 'WrongPassword@123',
        ])
    )->assertUnauthorized();

    expect($user->fresh()->password)->toBe($originalHash);

    $this->getJson('/api/auth/customer/me')
        ->assertUnauthorized();
});

it('rejects a disabled customer even with the correct password', function () {
    customerLoginAccount(['is_active' => false]);

    $this->postJson(
        '/api/auth/customer/login',
        customerLoginPayload()
    )->assertUnauthorized();

    $this->getJson('/api/auth/customer/me')
        ->assertUnauthorized();
});

it('rejects staff and admin accounts through customer login', function (string $role) {
    User::factory()->create([
        'email' => 'login-customer@example.test',
        'password' => Hash::make('CustomerPassword@123'),
        'role' => $role,
        'is_active' => true,
    ]);

    $this->postJson(
        '/api/auth/customer/login',
        customerLoginPayload()
    )->assertUnauthorized();

    $this->getJson('/api/auth/customer/me')
        ->assertUnauthorized();

    $this->getJson('/api/auth/staff/me')
        ->assertUnauthorized();
})->with(['staff', 'admin']);

it('rejects missing login fields', function (string $field) {
    customerLoginAccount();

    $payload = customerLoginPayload();
    unset($payload[$field]);

    assertCustomerLoginValidationRejected($this, $payload, $field);
})->with(['email', 'password']);

it('rejects invalid login inputs', function (string $field, mixed $value) {
    customerLoginAccount();

    assertCustomerLoginValidationRejected(
        $this,
        customerLoginPayload([$field => $value]),
        $field
    );
})->with([
    'null email' => ['email', null],
    'empty email' => ['email', ''],
    'whitespace email' => ['email', '   '],
    'malformed email' => ['email', 'not-an-email'],
    'email without domain' => ['email', 'customer@'],
    'array email' => ['email', ['login-customer@example.test']],
    'numeric email' => ['email', 123],
    'boolean email' => ['email', true],
    'email over 255 characters' => [
        'email',
        str_repeat('a', 64).'@'.
        str_repeat('b', 63).'.'.
        str_repeat('c', 63).'.'.
        str_repeat('d', 63).'.test',
    ],
    'SQL fragment as email' => ['email', "' OR 1=1 --"],
    'HTML as email' => ['email', '<script>alert(1)</script>'],

    'null password' => ['password', null],
    'empty password' => ['password', ''],
    'array password' => ['password', ['CustomerPassword@123']],
    'numeric password' => ['password', 123456789012],
    'boolean password' => ['password', true],
    'password over 72 ASCII bytes' => [
        'password',
        str_repeat('a', 73),
    ],
    'password over 72 multibyte bytes' => [
        'password',
        'Aa1!'.str_repeat('é', 35),
    ],
]);

it('rejects unexpected fields instead of trusting client account attributes', function (
    string $field,
    mixed $value
) {
    customerLoginAccount();

    assertCustomerLoginValidationRejected(
        $this,
        customerLoginPayload([$field => $value]),
        $field
    );
})->with([
    'user id' => ['user_id', 1],
    'primary key' => ['id', 1],
    'admin role' => ['role', 'admin'],
    'customer role' => ['role', 'customer'],
    'permissions' => ['permissions', ['*']],
    'active flag' => ['is_active', true],
    'account type' => ['account_type', 'staff'],
    'remember flag' => ['remember', true],
    'confirmation field' => [
        'password_confirmation',
        'CustomerPassword@123',
    ],
    'nested account' => ['account', ['role' => 'admin']],
    'arbitrary field' => ['unexpected', 'value'],
]);

it('does not apply new password strength requirements to existing login passwords', function (
    string $password
) {
    customerLoginAccount([
        'password' => Hash::make($password),
    ]);

    $this->postJson(
        '/api/auth/customer/login',
        customerLoginPayload(['password' => $password])
    )->assertOk();
})->with([
    'short legacy password' => ['abc'],
    'lowercase legacy password' => ['oldpassword'],
    'numeric legacy password' => ['123456'],
]);

it('accepts a correct existing password at the 72 byte boundary', function (
    string $password
) {
    expect(strlen($password))->toBe(72);

    customerLoginAccount([
        'password' => Hash::make($password),
    ]);

    $this->postJson(
        '/api/auth/customer/login',
        customerLoginPayload(['password' => $password])
    )->assertOk();
})->with([
    'ASCII password' => ['Aa1!'.str_repeat('a', 68)],
    'multibyte password' => ['Aa1!'.str_repeat('é', 34)],
]);

it('treats password casing as significant', function () {
    customerLoginAccount();

    $this->postJson(
        '/api/auth/customer/login',
        customerLoginPayload([
            'password' => 'customerpassword@123',
        ])
    )->assertUnauthorized();
});

it('does not interpret SQL syntax inside a password as a login bypass', function () {
    customerLoginAccount();

    $this->postJson(
        '/api/auth/customer/login',
        customerLoginPayload([
            'password' => "' OR 1=1 --",
        ])
    )->assertUnauthorized();

    $this->getJson('/api/auth/customer/me')
        ->assertUnauthorized();
});

it('does not expose password or internal authentication fields', function () {
    customerLoginAccount();

    $response = $this->postJson(
        '/api/auth/customer/login',
        customerLoginPayload()
    );

    $response->assertOk();

    $forbiddenKeys = [
        'password',
        'password_hash',
        'password_confirmation',
        'remember_token',
        'token_hash',
        'session_id',
        'reset_token',
        'verification_token',
    ];

    $inspect = function (array $data) use (&$inspect, $forbiddenKeys): void {
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                expect(strtolower($key))->not->toBeIn($forbiddenKeys);
            }

            if (is_array($value)) {
                $inspect($value);
            }
        }
    };

    $inspect($response->json());
});

it('does not change account privileges or create records during login', function () {
    $user = customerLoginAccount();

    $usersBefore = DB::table('users')->count();
    $customersBefore = DB::table('customers')->count();

    $this->postJson(
        '/api/auth/customer/login',
        customerLoginPayload()
    )->assertOk();

    $user->refresh();

    expect($user->role)->toBe('customer');
    expect($user->is_active)->toBeTrue();

    expect(DB::table('users')->count())->toBe($usersBefore);
    expect(DB::table('customers')->count())->toBe($customersBefore);

    Notification::assertNothingSent();
});
