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
        'auth.email_enabled' => false,
        'session.driver' => 'database',
        'session.lottery' => [0, 100],
    ]);

    Notification::fake();
});

function staffLoginAccount(array $overrides = []): User
{
    return User::factory()->create(array_replace([
        'email' => 'staff-login@example.test',
        'password' => Hash::make('StaffPassword@123'),
        'role' => 'staff',
        'is_active' => true,
        'email_verified_at' => now(),
    ], $overrides));
}

function staffLoginPayload(array $overrides = []): array
{
    return array_replace([
        'email' => 'staff-login@example.test',
        'password' => 'StaffPassword@123',
    ], $overrides);
}

function assertStaffLoginValidationRejected(
    $test,
    array $payload,
    string $field
): void {
    $test->postJson('/api/auth/staff/login', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    $test->getJson('/api/auth/staff/me')
        ->assertUnauthorized();
}

it('logs in active staff and admin accounts', function (string $role) {
    $user = staffLoginAccount(['role' => $role]);

    $response = $this->postJson(
        '/api/auth/staff/login',
        staffLoginPayload()
    );

    $response
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.user.role', $role)
        ->assertJsonStructure([
            'data' => ['user', 'csrf_token'],
        ]);

    $token = $response->json('data.csrf_token');

    expect($token)->toBeString();
    expect($token)->not->toBe('');
    expect($token)->toBe(session()->token());
})->with(['staff', 'admin']);

it('allows active unverified staff and admin accounts to log in', function (
    string $role
) {
    $user = staffLoginAccount([
        'role' => $role,
        'email_verified_at' => null,
    ]);

    $this->postJson(
        '/api/auth/staff/login',
        staffLoginPayload()
    )->assertOk();

    expect($user->fresh()->email_verified_at)->toBeNull();
})->with(['staff', 'admin']);

it('establishes the matching staff session', function (string $role) {
    $user = staffLoginAccount(['role' => $role]);

    $this->postJson(
        '/api/auth/staff/login',
        staffLoginPayload()
    )->assertOk();

    $this->getJson('/api/auth/staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.user.role', $role);
})->with(['staff', 'admin']);

it('does not grant customer access after staff login', function (string $role) {
    staffLoginAccount(['role' => $role]);

    $this->postJson(
        '/api/auth/staff/login',
        staffLoginPayload()
    )->assertOk();

    $this->getJson('/api/auth/customer/me')
        ->assertUnauthorized();
})->with(['staff', 'admin']);

it('rejects customer accounts through staff login', function () {
    $user = staffLoginAccount(['role' => 'customer']);

    DB::table('customers')->insert([
        'user_id' => $user->id,
    ]);

    $this->postJson(
        '/api/auth/staff/login',
        staffLoginPayload()
    )->assertUnauthorized();

    $this->getJson('/api/auth/staff/me')
        ->assertUnauthorized();

    $this->getJson('/api/auth/customer/me')
        ->assertUnauthorized();
});

it('rejects disabled staff and admin accounts', function (string $role) {
    staffLoginAccount([
        'role' => $role,
        'is_active' => false,
    ]);

    $this->postJson(
        '/api/auth/staff/login',
        staffLoginPayload()
    )->assertUnauthorized();

    $this->getJson('/api/auth/staff/me')
        ->assertUnauthorized();
})->with(['staff', 'admin']);

it('rejects an unknown account without creating records', function () {
    $usersBefore = DB::table('users')->count();
    $customersBefore = DB::table('customers')->count();

    $this->postJson(
        '/api/auth/staff/login',
        staffLoginPayload()
    )->assertUnauthorized();

    expect(DB::table('users')->count())->toBe($usersBefore);
    expect(DB::table('customers')->count())->toBe($customersBefore);

    $this->getJson('/api/auth/staff/me')
        ->assertUnauthorized();
});

it('rejects wrong passwords without modifying the account', function (
    string $role
) {
    $user = staffLoginAccount(['role' => $role]);
    $originalHash = $user->password;

    $this->postJson(
        '/api/auth/staff/login',
        staffLoginPayload([
            'password' => 'WrongPassword@123',
        ])
    )->assertUnauthorized();

    $user->refresh();

    expect($user->password)->toBe($originalHash);
    expect($user->role)->toBe($role);

    $this->getJson('/api/auth/staff/me')
        ->assertUnauthorized();
})->with(['staff', 'admin']);

it('rejects missing required fields', function (string $field) {
    staffLoginAccount();

    $payload = staffLoginPayload();
    unset($payload[$field]);

    assertStaffLoginValidationRejected($this, $payload, $field);
})->with(['email', 'password']);

it('rejects invalid input values', function (string $field, mixed $value) {
    staffLoginAccount();

    assertStaffLoginValidationRejected(
        $this,
        staffLoginPayload([$field => $value]),
        $field
    );
})->with([
    'null email' => ['email', null],
    'empty email' => ['email', ''],
    'whitespace email' => ['email', '   '],
    'malformed email' => ['email', 'not-an-email'],
    'email without domain' => ['email', 'staff@'],
    'array email' => ['email', ['staff-login@example.test']],
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
    'array password' => ['password', ['StaffPassword@123']],
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

it('rejects unexpected fields and attempted privilege injection', function (
    string $field,
    mixed $value
) {
    staffLoginAccount();

    assertStaffLoginValidationRejected(
        $this,
        staffLoginPayload([$field => $value]),
        $field
    );
})->with([
    'user id' => ['user_id', 1],
    'primary key' => ['id', 1],
    'admin role' => ['role', 'admin'],
    'staff role' => ['role', 'staff'],
    'permissions' => ['permissions', ['*']],
    'active flag' => ['is_active', true],
    'account type' => ['account_type', 'staff'],
    'remember flag' => ['remember', true],
    'password confirmation' => [
        'password_confirmation',
        'StaffPassword@123',
    ],
    'nested account' => ['account', ['role' => 'admin']],
    'arbitrary field' => ['unexpected', 'value'],
]);

it('accepts existing passwords without applying new password strength rules', function (
    string $password
) {
    staffLoginAccount([
        'password' => Hash::make($password),
    ]);

    $this->postJson(
        '/api/auth/staff/login',
        staffLoginPayload(['password' => $password])
    )->assertOk();
})->with([
    'short legacy password' => ['abc'],
    'lowercase legacy password' => ['oldpassword'],
    'numeric legacy password' => ['123456'],
]);

it('accepts correct passwords at the 72 byte boundary', function (
    string $password
) {
    expect(strlen($password))->toBe(72);

    staffLoginAccount([
        'password' => Hash::make($password),
    ]);

    $this->postJson(
        '/api/auth/staff/login',
        staffLoginPayload(['password' => $password])
    )->assertOk();
})->with([
    'ASCII password' => ['Aa1!'.str_repeat('a', 68)],
    'multibyte password' => ['Aa1!'.str_repeat('é', 34)],
]);

it('treats password casing as significant', function () {
    staffLoginAccount();

    $this->postJson(
        '/api/auth/staff/login',
        staffLoginPayload([
            'password' => 'staffpassword@123',
        ])
    )->assertUnauthorized();
});

it('does not treat SQL syntax in a password as an authentication bypass', function () {
    staffLoginAccount();

    $this->postJson(
        '/api/auth/staff/login',
        staffLoginPayload([
            'password' => "' OR 1=1 --",
        ])
    )->assertUnauthorized();

    $this->getJson('/api/auth/staff/me')
        ->assertUnauthorized();
});

it('keeps staff login available while email features are disabled', function () {
    config(['auth.email_enabled' => false]);

    staffLoginAccount(['email_verified_at' => null]);

    $this->postJson(
        '/api/auth/staff/login',
        staffLoginPayload()
    )->assertOk();

    Notification::assertNothingSent();
});

it('does not expose passwords or internal authentication fields', function () {
    staffLoginAccount();

    $response = $this->postJson(
        '/api/auth/staff/login',
        staffLoginPayload()
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

it('does not change privileges or create records during login', function (
    string $role
) {
    $user = staffLoginAccount(['role' => $role]);

    $usersBefore = DB::table('users')->count();
    $customersBefore = DB::table('customers')->count();

    $this->postJson(
        '/api/auth/staff/login',
        staffLoginPayload()
    )->assertOk();

    $user->refresh();

    expect($user->role)->toBe($role);
    expect($user->is_active)->toBeTrue();

    expect(DB::table('users')->count())->toBe($usersBefore);
    expect(DB::table('customers')->count())->toBe($customersBefore);

    Notification::assertNothingSent();
})->with(['staff', 'admin']);
