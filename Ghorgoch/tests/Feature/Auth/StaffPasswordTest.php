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

function staffPasswordAccount(array $overrides = []): User
{
    return User::factory()->create(array_replace([
        'email' => 'staff-password@example.test',
        'password' => Hash::make('OldStaffPassword@123'),
        'role' => 'staff',
        'is_active' => true,
    ], $overrides));
}

function staffPasswordPayload(array $overrides = []): array
{
    return array_replace([
        'current_password' => 'OldStaffPassword@123',
        'password' => 'NewStaffPassword@456',
        'password_confirmation' => 'NewStaffPassword@456',
    ], $overrides);
}

/**
 * Reload authentication and session state between requests.
 * CSRF enforcement is covered separately.
 */
function staffPasswordRequest(
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

function staffPasswordLogin(
    TestCase $test,
    array &$cookies,
    User $user,
    string $password = 'OldStaffPassword@123',
    string $type = 'staff'
): void {
    staffPasswordRequest(
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

function assertStaffPasswordRejected(
    TestCase $test,
    array $payload,
    string $field,
    string $role = 'staff'
): void {
    $user = staffPasswordAccount(['role' => $role]);
    $originalHash = $user->password;

    $firstDevice = [];
    $secondDevice = [];

    staffPasswordLogin($test, $firstDevice, $user);
    staffPasswordLogin($test, $secondDevice, $user);

    staffPasswordRequest(
        $test,
        $firstDevice,
        'POST',
        'staff/password',
        $payload,
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect($user->fresh()->password)->toBe($originalHash);

    staffPasswordRequest($test, $firstDevice, 'GET', 'staff/me')
        ->assertOk();

    staffPasswordRequest($test, $secondDevice, 'GET', 'staff/me')
        ->assertOk();
}

it('changes a staff or admin password and stores a valid hash', function (
    string $role
) {
    $user = staffPasswordAccount(['role' => $role]);
    $cookies = [];

    staffPasswordLogin($this, $cookies, $user);

    staffPasswordRequest(
        $this,
        $cookies,
        'POST',
        'staff/password',
        staffPasswordPayload(),
    )
        ->assertOk()
        ->assertJsonStructure(['message', 'data']);

    $user->refresh();

    expect($user->password)->not->toBe('NewStaffPassword@456');
    expect(Hash::check('NewStaffPassword@456', $user->password))
        ->toBeTrue();
    expect(Hash::check('OldStaffPassword@123', $user->password))
        ->toBeFalse();
})->with(['staff', 'admin']);

it('ends all own sessions including the initiating session', function (
    string $role
) {
    $user = staffPasswordAccount(['role' => $role]);

    $firstDevice = [];
    $secondDevice = [];
    $thirdDevice = [];

    staffPasswordLogin($this, $firstDevice, $user);
    $firstId = session()->getId();

    staffPasswordLogin($this, $secondDevice, $user);
    $secondId = session()->getId();

    staffPasswordLogin($this, $thirdDevice, $user);
    $thirdId = session()->getId();

    $oldFirstCookies = $firstDevice;

    staffPasswordRequest(
        $this,
        $secondDevice,
        'POST',
        'staff/password',
        staffPasswordPayload(),
    )->assertOk();

    foreach ([$firstId, $secondId, $thirdId] as $id) {
        $this->assertDatabaseMissing('sessions', ['id' => $id]);
    }

    staffPasswordRequest($this, $firstDevice, 'GET', 'staff/me')
        ->assertUnauthorized();

    staffPasswordRequest($this, $secondDevice, 'GET', 'staff/me')
        ->assertUnauthorized();

    staffPasswordRequest($this, $thirdDevice, 'GET', 'staff/me')
        ->assertUnauthorized();

    staffPasswordRequest($this, $oldFirstCookies, 'GET', 'staff/me')
        ->assertUnauthorized();
})->with(['staff', 'admin']);

it('rejects the old password and accepts the new password on fresh login', function (
    string $role
) {
    $user = staffPasswordAccount(['role' => $role]);
    $cookies = [];

    staffPasswordLogin($this, $cookies, $user);

    staffPasswordRequest(
        $this,
        $cookies,
        'POST',
        'staff/password',
        staffPasswordPayload(),
    )->assertOk();

    staffPasswordRequest(
        $this,
        $cookies,
        'POST',
        'staff/login',
        [
            'email' => $user->email,
            'password' => 'OldStaffPassword@123',
        ],
    )->assertUnauthorized();

    staffPasswordLogin(
        $this,
        $cookies,
        $user,
        'NewStaffPassword@456',
    );

    staffPasswordRequest($this, $cookies, 'GET', 'staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.role', $role);
})->with(['staff', 'admin']);

it('rotates the initiating sessions id and csrf token', function (string $role) {
    $user = staffPasswordAccount(['role' => $role]);
    $cookies = [];

    staffPasswordLogin($this, $cookies, $user);

    $oldId = session()->getId();
    $oldToken = session()->token();

    staffPasswordRequest(
        $this,
        $cookies,
        'POST',
        'staff/password',
        staffPasswordPayload(),
    )->assertOk();

    expect(session()->getId())->not->toBe($oldId);
    expect(session()->token())->not->toBe($oldToken);
    expect(session()->token())->toBeString()->not->toBe('');
})->with(['staff', 'admin']);

it('rejects a wrong current password without changing the password or sessions', function (
    string $role
) {
    assertStaffPasswordRejected(
        $this,
        staffPasswordPayload([
            'current_password' => 'WrongCurrentPassword@123',
        ]),
        'current_password',
        $role,
    );
})->with(['staff', 'admin']);

it('rejects an unchanged new password', function (string $role) {
    assertStaffPasswordRejected(
        $this,
        staffPasswordPayload([
            'password' => 'OldStaffPassword@123',
            'password_confirmation' => 'OldStaffPassword@123',
        ]),
        'password',
        $role,
    );
})->with(['staff', 'admin']);

it('rejects missing required fields', function (string $field) {
    $payload = staffPasswordPayload();
    unset($payload[$field]);

    assertStaffPasswordRejected($this, $payload, $field);
})->with([
    'current_password',
    'password',
    'password_confirmation',
]);

it('rejects invalid field values', function (string $field, mixed $value) {
    assertStaffPasswordRejected(
        $this,
        staffPasswordPayload([$field => $value]),
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
    assertStaffPasswordRejected(
        $this,
        staffPasswordPayload([
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

it('rejects mismatched confirmation', function () {
    assertStaffPasswordRejected(
        $this,
        staffPasswordPayload([
            'password_confirmation' => 'DifferentPassword@789',
        ]),
        'password',
    );
});

it('accepts new passwords at the documented boundaries', function (
    string $role,
    string $password
) {
    $user = staffPasswordAccount(['role' => $role]);
    $cookies = [];

    staffPasswordLogin($this, $cookies, $user);

    staffPasswordRequest(
        $this,
        $cookies,
        'POST',
        'staff/password',
        staffPasswordPayload([
            'password' => $password,
            'password_confirmation' => $password,
        ]),
    )->assertOk();

    expect(Hash::check($password, $user->fresh()->password))->toBeTrue();
})->with(['staff', 'admin'])->with([
    'exactly 12 characters' => ['Aa1!aaaaaaaa'],
    'exactly 72 ASCII bytes' => ['Aa1!'.str_repeat('a', 68)],
    'exactly 72 multibyte bytes' => ['Aa1!'.str_repeat('é', 34)],
]);

it('accepts correct legacy current passwords without imposing new password strength rules', function (
    string $oldPassword
) {
    $user = staffPasswordAccount([
        'password' => Hash::make($oldPassword),
    ]);

    $cookies = [];

    staffPasswordLogin($this, $cookies, $user, $oldPassword);

    staffPasswordRequest(
        $this,
        $cookies,
        'POST',
        'staff/password',
        staffPasswordPayload([
            'current_password' => $oldPassword,
        ]),
    )->assertOk();

    expect(Hash::check(
        'NewStaffPassword@456',
        $user->fresh()->password,
    ))->toBeTrue();
})->with([
    'short legacy password' => ['abc'],
    'numeric legacy password' => ['123456'],
]);

it('rejects unexpected fields without changing the password or sessions', function (
    string $role,
    string $field,
    mixed $value
) {
    assertStaffPasswordRejected(
        $this,
        staffPasswordPayload([$field => $value]),
        $field,
        $role,
    );
})->with(['staff', 'admin'])->with([
    'user id' => ['user_id', 999999],
    'role' => ['role', 'admin'],
    'permissions' => ['permissions', ['*']],
    'active flag' => ['is_active', true],
    'email' => ['email', 'attacker@example.test'],
    'nested account' => ['account', ['role' => 'admin']],
    'arbitrary field' => ['unexpected', 'value'],
]);

it('rejects unauthenticated requests', function () {
    $cookies = [];

    staffPasswordRequest(
        $this,
        $cookies,
        'POST',
        'staff/password',
        staffPasswordPayload(),
    )->assertUnauthorized();
});

it('rejects a forged session cookie', function () {
    $cookies = [
        config('session.cookie') => 'attacker-controlled-cookie',
    ];

    staffPasswordRequest(
        $this,
        $cookies,
        'POST',
        'staff/password',
        staffPasswordPayload(),
    )->assertUnauthorized();
});

it('rejects customer sessions without changing the customers password', function () {
    $user = staffPasswordAccount(['role' => 'customer']);

    DB::table('customers')->insert([
        'user_id' => $user->id,
    ]);

    $originalHash = $user->password;
    $cookies = [];

    staffPasswordLogin($this, $cookies, $user, type: 'customer');

    staffPasswordRequest(
        $this,
        $cookies,
        'POST',
        'staff/password',
        staffPasswordPayload(),
    )->assertUnauthorized();

    expect($user->fresh()->password)->toBe($originalHash);

    staffPasswordRequest($this, $cookies, 'GET', 'customer/me')
        ->assertOk();
});

it('rejects an expired session without changing the password', function () {
    $user = staffPasswordAccount();
    $originalHash = $user->password;
    $cookies = [];

    staffPasswordLogin($this, $cookies, $user);

    $this->travel(121)->minutes();

    staffPasswordRequest(
        $this,
        $cookies,
        'POST',
        'staff/password',
        staffPasswordPayload(),
    )->assertUnauthorized();

    expect($user->fresh()->password)->toBe($originalHash);
});

it('rejects a revoked session without changing the password', function () {
    $user = staffPasswordAccount();
    $originalHash = $user->password;
    $cookies = [];

    staffPasswordLogin($this, $cookies, $user);

    DB::table('sessions')
        ->where('id', session()->getId())
        ->delete();

    staffPasswordRequest(
        $this,
        $cookies,
        'POST',
        'staff/password',
        staffPasswordPayload(),
    )->assertUnauthorized();

    expect($user->fresh()->password)->toBe($originalHash);
});

it('does not change another accounts password or revoke its session', function (
    string $role,
    string $otherRole
) {
    $user = staffPasswordAccount(['role' => $role]);

    $other = staffPasswordAccount([
        'email' => 'other-password@example.test',
        'role' => $otherRole,
    ]);

    $otherHash = $other->password;

    $cookies = [];
    $otherCookies = [];

    staffPasswordLogin($this, $cookies, $user);
    staffPasswordLogin($this, $otherCookies, $other);

    staffPasswordRequest(
        $this,
        $cookies,
        'POST',
        'staff/password',
        staffPasswordPayload(),
    )->assertOk();

    expect($other->fresh()->password)->toBe($otherHash);

    staffPasswordRequest($this, $otherCookies, 'GET', 'staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $other->email);
})->with([
    'staff changes own password' => ['staff', 'admin'],
    'admin changes own password' => ['admin', 'staff'],
]);

it('allows an unverified account to change its password without verifying email', function (
    string $role
) {
    $user = staffPasswordAccount([
        'role' => $role,
        'email_verified_at' => null,
    ]);

    $cookies = [];

    staffPasswordLogin($this, $cookies, $user);

    staffPasswordRequest(
        $this,
        $cookies,
        'POST',
        'staff/password',
        staffPasswordPayload(),
    )->assertOk();

    expect($user->fresh()->email_verified_at)->toBeNull();
})->with(['staff', 'admin']);

it('preserves account details and records while email features are disabled', function (
    string $role
) {
    $user = staffPasswordAccount(['role' => $role]);

    $originalEmail = $user->email;
    $originalVerifiedAt = $user->email_verified_at?->toISOString();

    $usersBefore = DB::table('users')->count();
    $customersBefore = DB::table('customers')->count();

    $cookies = [];

    staffPasswordLogin($this, $cookies, $user);

    $response = staffPasswordRequest(
        $this,
        $cookies,
        'POST',
        'staff/password',
        staffPasswordPayload(),
    );

    $response->assertOk();

    $user->refresh();

    expect($user->email)->toBe($originalEmail);
    expect($user->role)->toBe($role);
    expect($user->is_active)->toBeTrue();
    expect($user->email_verified_at?->toISOString())
        ->toBe($originalVerifiedAt);

    expect(DB::table('users')->count())->toBe($usersBefore);
    expect(DB::table('customers')->count())->toBe($customersBefore);

    expect($response->getContent())
        ->not->toContain('OldStaffPassword@123')
        ->not->toContain('NewStaffPassword@456');

    Notification::assertNothingSent();
})->with(['staff', 'admin']);
