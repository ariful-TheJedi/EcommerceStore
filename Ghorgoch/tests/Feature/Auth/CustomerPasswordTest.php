<?php

use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

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

afterEach(function () {
    $this->travelBack();
});

function customerPasswordAccount(array $overrides = []): User
{
    $user = User::factory()->create(array_replace([
        'email' => 'customer-password@example.test',
        'password' => Hash::make('OldCustomerPassword@123'),
        'role' => 'customer',
        'is_active' => true,
    ], $overrides));

    DB::table('customers')->insert([
        'user_id' => $user->id,
    ]);

    return $user;
}

function customerPasswordPayload(array $overrides = []): array
{
    return array_replace([
        'current_password' => 'OldCustomerPassword@123',
        'password' => 'NewCustomerPassword@456',
        'password_confirmation' => 'NewCustomerPassword@456',
    ], $overrides);
}

/**
 * Reload session/authentication services between HTTP requests.
 * CSRF enforcement is covered by CsrfProtectionTest.php.
 */
function customerPasswordRequest(
    TestCase $test,
    array &$cookies,
    string $method,
    string $path,
    array $data = []
): TestResponse {
    Auth::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');

    $response = $test->call(
        $method,
        '/api/auth/'.$path,
        [],
        $cookies,
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        $method === 'GET'
            ? null
            : json_encode($data, JSON_THROW_ON_ERROR),
    );

    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === config('session.cookie')) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
    }

    return $response;
}

function customerPasswordLogin(
    TestCase $test,
    array &$cookies,
    User $user,
    string $password = 'OldCustomerPassword@123',
    string $type = 'customer'
): void {
    customerPasswordRequest(
        $test,
        $cookies,
        'POST',
        $type.'/login',
        [
            'email' => $user->email,
            'password' => $password,
        ],
    )->assertOk();
}

function assertCustomerPasswordRejected(
    TestCase $test,
    array $payload,
    string $field
): void {
    $user = customerPasswordAccount();
    $originalHash = $user->password;

    $firstDevice = [];
    $secondDevice = [];

    customerPasswordLogin($test, $firstDevice, $user);
    customerPasswordLogin($test, $secondDevice, $user);

    customerPasswordRequest(
        $test,
        $firstDevice,
        'POST',
        'customer/password',
        $payload,
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect($user->fresh()->password)->toBe($originalHash);

    customerPasswordRequest(
        $test, $firstDevice, 'GET', 'customer/me'
    )->assertOk();

    customerPasswordRequest(
        $test, $secondDevice, 'GET', 'customer/me'
    )->assertOk();
}

it('changes the password and stores a valid hash', function () {
    $user = customerPasswordAccount();
    $cookies = [];

    customerPasswordLogin($this, $cookies, $user);

    customerPasswordRequest(
        $this,
        $cookies,
        'POST',
        'customer/password',
        customerPasswordPayload(),
    )
        ->assertOk()
        ->assertJsonStructure(['message', 'data']);

    $user->refresh();

    expect($user->password)->not->toBe('NewCustomerPassword@456');

    expect(Hash::check(
        'NewCustomerPassword@456',
        $user->password
    ))->toBeTrue();

    expect(Hash::check(
        'OldCustomerPassword@123',
        $user->password
    ))->toBeFalse();
});

it('ends all customer sessions including the initiating session', function () {
    $user = customerPasswordAccount();

    $firstDevice = [];
    $secondDevice = [];
    $thirdDevice = [];

    customerPasswordLogin($this, $firstDevice, $user);
    $firstId = session()->getId();

    customerPasswordLogin($this, $secondDevice, $user);
    $secondId = session()->getId();

    customerPasswordLogin($this, $thirdDevice, $user);
    $thirdId = session()->getId();

    $oldFirstCookies = $firstDevice;

    customerPasswordRequest(
        $this,
        $secondDevice,
        'POST',
        'customer/password',
        customerPasswordPayload(),
    )->assertOk();

    foreach ([$firstId, $secondId, $thirdId] as $id) {
        $this->assertDatabaseMissing('sessions', ['id' => $id]);
    }

    customerPasswordRequest(
        $this, $firstDevice, 'GET', 'customer/me'
    )->assertUnauthorized();

    customerPasswordRequest(
        $this, $secondDevice, 'GET', 'customer/me'
    )->assertUnauthorized();

    customerPasswordRequest(
        $this, $thirdDevice, 'GET', 'customer/me'
    )->assertUnauthorized();

    customerPasswordRequest(
        $this, $oldFirstCookies, 'GET', 'customer/me'
    )->assertUnauthorized();
});

it('rejects the old password and accepts the new password on fresh login', function () {
    $user = customerPasswordAccount();
    $cookies = [];

    customerPasswordLogin($this, $cookies, $user);

    customerPasswordRequest(
        $this,
        $cookies,
        'POST',
        'customer/password',
        customerPasswordPayload(),
    )->assertOk();

    customerPasswordRequest(
        $this,
        $cookies,
        'POST',
        'customer/login',
        [
            'email' => $user->email,
            'password' => 'OldCustomerPassword@123',
        ],
    )->assertUnauthorized();

    customerPasswordLogin(
        $this,
        $cookies,
        $user,
        'NewCustomerPassword@456',
    );

    customerPasswordRequest(
        $this, $cookies, 'GET', 'customer/me'
    )->assertOk();
});

it('rotates the initiating sessions id and csrf token', function () {
    $user = customerPasswordAccount();
    $cookies = [];

    customerPasswordLogin($this, $cookies, $user);

    $oldId = session()->getId();
    $oldToken = session()->token();

    customerPasswordRequest(
        $this,
        $cookies,
        'POST',
        'customer/password',
        customerPasswordPayload(),
    )->assertOk();

    expect(session()->getId())->not->toBe($oldId);
    expect(session()->token())->not->toBe($oldToken);
    expect(session()->token())->toBeString()->not->toBe('');
});

it('rejects a wrong current password without changing the account or sessions', function () {
    assertCustomerPasswordRejected(
        $this,
        customerPasswordPayload([
            'current_password' => 'WrongCurrentPassword@123',
        ]),
        'current_password',
    );
});

it('rejects an unchanged new password', function () {
    assertCustomerPasswordRejected(
        $this,
        customerPasswordPayload([
            'password' => 'OldCustomerPassword@123',
            'password_confirmation' => 'OldCustomerPassword@123',
        ]),
        'password',
    );
});

it('rejects missing required fields', function (string $field) {
    $payload = customerPasswordPayload();
    unset($payload[$field]);

    assertCustomerPasswordRejected($this, $payload, $field);
})->with([
    'current_password',
    'password',
    'password_confirmation',
]);

it('rejects invalid field values', function (string $field, mixed $value) {
    assertCustomerPasswordRejected(
        $this,
        customerPasswordPayload([$field => $value]),
        $field,
    );
})->with([
    'null current password' => ['current_password', null],
    'empty current password' => ['current_password', ''],
    'array current password' => ['current_password', ['invalid']],
    'numeric current password' => ['current_password', 123456],
    'boolean current password' => ['current_password', true],
    'current password over 72 bytes' => [
        'current_password',
        str_repeat('a', 73),
    ],
    'multibyte current password over 72 bytes' => [
        'current_password',
        'Aa1!'.str_repeat('é', 35),
    ],
    'null new password' => ['password', null],
    'empty new password' => ['password', ''],
    'array new password' => ['password', ['invalid']],
    'numeric new password' => ['password', 123456],
    'boolean new password' => ['password', true],
    'null confirmation' => ['password_confirmation', null],
    'array confirmation' => ['password_confirmation', ['invalid']],
]);

it('rejects weak or oversized new passwords', function (string $password) {
    assertCustomerPasswordRejected(
        $this,
        customerPasswordPayload([
            'password' => $password,
            'password_confirmation' => $password,
        ]),
        'password',
    );
})->with([
    'eleven characters' => ['Aa1!aaaaaaa'],
    'no uppercase' => ['lowercase123!'],
    'no lowercase' => ['UPPERCASE123!'],
    'no number' => ['StrongPassword!'],
    'no symbol' => ['StrongPassword123'],
    '73 ASCII bytes' => ['Aa1!'.str_repeat('a', 69)],
    'over 72 multibyte bytes' => ['Aa1!'.str_repeat('é', 35)],
]);

it('rejects mismatched password confirmation', function () {
    assertCustomerPasswordRejected(
        $this,
        customerPasswordPayload([
            'password_confirmation' => 'DifferentPassword@789',
        ]),
        'password',
    );
});

it('accepts new passwords at the documented boundaries', function (
    string $password
) {
    $user = customerPasswordAccount();
    $cookies = [];

    customerPasswordLogin($this, $cookies, $user);

    customerPasswordRequest(
        $this,
        $cookies,
        'POST',
        'customer/password',
        customerPasswordPayload([
            'password' => $password,
            'password_confirmation' => $password,
        ]),
    )->assertOk();

    expect(Hash::check($password, $user->fresh()->password))->toBeTrue();
})->with([
    'exactly 12 characters' => ['Aa1!aaaaaaaa'],
    'exactly 72 ASCII bytes' => ['Aa1!'.str_repeat('a', 68)],
    'exactly 72 multibyte bytes' => ['Aa1!'.str_repeat('é', 34)],
]);

it('accepts a correct legacy current password without applying new password strength rules', function (
    string $oldPassword
) {
    $user = customerPasswordAccount([
        'password' => Hash::make($oldPassword),
    ]);

    $cookies = [];

    customerPasswordLogin($this, $cookies, $user, $oldPassword);

    customerPasswordRequest(
        $this,
        $cookies,
        'POST',
        'customer/password',
        customerPasswordPayload([
            'current_password' => $oldPassword,
        ]),
    )->assertOk();

    expect(Hash::check(
        'NewCustomerPassword@456',
        $user->fresh()->password,
    ))->toBeTrue();
})->with([
    'short legacy password' => ['abc'],
    'numeric legacy password' => ['123456'],
]);

it('rejects unexpected fields without changing the password or sessions', function (
    string $field,
    mixed $value
) {
    assertCustomerPasswordRejected(
        $this,
        customerPasswordPayload([$field => $value]),
        $field,
    );
})->with([
    'user id' => ['user_id', 999999],
    'role' => ['role', 'admin'],
    'permissions' => ['permissions', ['*']],
    'active flag' => ['is_active', true],
    'email' => ['email', 'attacker@example.test'],
    'nested account' => ['account', ['role' => 'admin']],
    'arbitrary field' => ['unexpected', 'value'],
]);

it('rejects requests without authentication', function () {
    $cookies = [];

    customerPasswordRequest(
        $this,
        $cookies,
        'POST',
        'customer/password',
        customerPasswordPayload(),
    )->assertUnauthorized();
});

it('rejects a forged session cookie', function () {
    $cookies = [
        config('session.cookie') => 'attacker-controlled-cookie',
    ];

    customerPasswordRequest(
        $this,
        $cookies,
        'POST',
        'customer/password',
        customerPasswordPayload(),
    )->assertUnauthorized();
});

it('rejects staff and admin sessions without changing their passwords', function (
    string $role
) {
    $user = User::factory()->create([
        'email' => 'wrong-type@example.test',
        'password' => Hash::make('OldCustomerPassword@123'),
        'role' => $role,
        'is_active' => true,
    ]);

    $originalHash = $user->password;
    $cookies = [];

    customerPasswordLogin(
        $this,
        $cookies,
        $user,
        type: 'staff',
    );

    customerPasswordRequest(
        $this,
        $cookies,
        'POST',
        'customer/password',
        customerPasswordPayload(),
    )->assertUnauthorized();

    expect($user->fresh()->password)->toBe($originalHash);

    customerPasswordRequest(
        $this, $cookies, 'GET', 'staff/me'
    )->assertOk();
})->with(['staff', 'admin']);

it('rejects an expired session without changing the password', function () {
    $user = customerPasswordAccount();
    $originalHash = $user->password;
    $cookies = [];

    customerPasswordLogin($this, $cookies, $user);

    $this->travel(121)->minutes();

    customerPasswordRequest(
        $this,
        $cookies,
        'POST',
        'customer/password',
        customerPasswordPayload(),
    )->assertUnauthorized();

    expect($user->fresh()->password)->toBe($originalHash);
});

it('rejects a revoked session without changing the password', function () {
    $user = customerPasswordAccount();
    $originalHash = $user->password;
    $cookies = [];

    customerPasswordLogin($this, $cookies, $user);

    DB::table('sessions')
        ->where('id', session()->getId())
        ->delete();

    customerPasswordRequest(
        $this,
        $cookies,
        'POST',
        'customer/password',
        customerPasswordPayload(),
    )->assertUnauthorized();

    expect($user->fresh()->password)->toBe($originalHash);
});

it('does not change another customers password or revoke their sessions', function () {
    $user = customerPasswordAccount();

    $other = customerPasswordAccount([
        'email' => 'other-password@example.test',
    ]);

    $otherHash = $other->password;

    $cookies = [];
    $otherCookies = [];

    customerPasswordLogin($this, $cookies, $user);
    customerPasswordLogin($this, $otherCookies, $other);

    customerPasswordRequest(
        $this,
        $cookies,
        'POST',
        'customer/password',
        customerPasswordPayload(),
    )->assertOk();

    expect($other->fresh()->password)->toBe($otherHash);

    customerPasswordRequest(
        $this, $otherCookies, 'GET', 'customer/me'
    )
        ->assertOk()
        ->assertJsonPath('data.user.email', $other->email);
});

it('allows an unverified customer to change their password without verifying their email', function () {
    $user = customerPasswordAccount([
        'email_verified_at' => null,
    ]);

    $cookies = [];

    customerPasswordLogin($this, $cookies, $user);

    customerPasswordRequest(
        $this,
        $cookies,
        'POST',
        'customer/password',
        customerPasswordPayload(),
    )->assertOk();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('preserves account details and records while email features are disabled', function () {
    $user = customerPasswordAccount();

    $originalEmail = $user->email;
    $originalVerifiedAt = $user->email_verified_at?->toISOString();

    $usersBefore = DB::table('users')->count();
    $customersBefore = DB::table('customers')->count();

    $cookies = [];

    customerPasswordLogin($this, $cookies, $user);

    $response = customerPasswordRequest(
        $this,
        $cookies,
        'POST',
        'customer/password',
        customerPasswordPayload(),
    );

    $response->assertOk();

    $user->refresh();

    expect($user->email)->toBe($originalEmail);
    expect($user->role)->toBe('customer');
    expect($user->is_active)->toBeTrue();
    expect($user->email_verified_at?->toISOString())
        ->toBe($originalVerifiedAt);

    expect(DB::table('users')->count())->toBe($usersBefore);
    expect(DB::table('customers')->count())->toBe($customersBefore);

    expect($response->getContent())
        ->not->toContain('OldCustomerPassword@123')
        ->not->toContain('NewCustomerPassword@456');

    Notification::assertNothingSent();
});
