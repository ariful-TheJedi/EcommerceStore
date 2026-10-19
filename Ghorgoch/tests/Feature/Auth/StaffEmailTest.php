<?php

use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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
        'auth.email_enabled' => true,
        'session.driver' => 'database',
        'session.lottery' => [0, 100],
    ]);

    Mail::fake();
    Notification::fake();
});

afterEach(function () {
    $this->travelBack();
});

function staffEmailAccount(array $overrides = []): User
{
    return User::factory()->create(array_replace([
        'email' => 'staff-email@example.test',
        'password' => Hash::make('StaffPassword@123'),
        'role' => 'staff',
        'is_active' => true,
        'email_verified_at' => now(),
    ], $overrides));
}

function staffEmailPayload(array $overrides = []): array
{
    return array_replace([
        'email' => 'changed-staff@example.test',
        'current_password' => 'StaffPassword@123',
    ], $overrides);
}

/**
 * Reload authentication/session services and replay raw session cookies.
 * CSRF enforcement is covered separately.
 */
function staffEmailRequest(
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

function staffEmailLogin(
    TestCase $test,
    array &$cookies,
    User $user,
    string $type = 'staff',
    string $password = 'StaffPassword@123'
): void {
    staffEmailRequest(
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

function assertStaffEmailRejected(
    TestCase $test,
    array $payload,
    string $field,
    string $role = 'staff'
): void {
    $user = staffEmailAccount(['role' => $role]);
    $original = $user->fresh()->getRawOriginal();

    $firstDevice = [];
    $secondDevice = [];

    staffEmailLogin($test, $firstDevice, $user);
    staffEmailLogin($test, $secondDevice, $user);

    staffEmailRequest(
        $test,
        $firstDevice,
        'POST',
        'staff/email',
        $payload,
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect($user->fresh()->getRawOriginal())->toBe($original);

    staffEmailRequest($test, $firstDevice, 'GET', 'staff/me')
        ->assertOk();

    staffEmailRequest($test, $secondDevice, 'GET', 'staff/me')
        ->assertOk();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
}

it('changes the email and requires verification without changing other account details', function (
    string $role
) {
    $user = staffEmailAccount(['role' => $role]);
    $originalHash = $user->password;
    $originalName = $user->name;

    $usersBefore = DB::table('users')->count();
    $customersBefore = DB::table('customers')->count();
    $cookies = [];

    staffEmailLogin($this, $cookies, $user);

    $response = staffEmailRequest(
        $this,
        $cookies,
        'POST',
        'staff/email',
        staffEmailPayload(),
    );

    $response
        ->assertOk()
        ->assertJsonStructure([
            'message',
            'data' => ['verification_sent'],
        ]);

    expect($response->json('data.verification_sent'))->toBeBool();

    $user->refresh();

    expect($user->email)->toBe('changed-staff@example.test');
    expect($user->email_verified_at)->toBeNull();
    expect($user->password)->toBe($originalHash);
    expect($user->name)->toBe($originalName);
    expect($user->role)->toBe($role);
    expect($user->is_active)->toBeTrue();

    expect(DB::table('users')->count())->toBe($usersBefore);
    expect(DB::table('customers')->count())->toBe($customersBefore);
})->with(['staff', 'admin']);

it('revokes all own sessions and rotates the initiating session', function (
    string $role
) {
    $user = staffEmailAccount(['role' => $role]);

    $firstDevice = [];
    $secondDevice = [];

    staffEmailLogin($this, $firstDevice, $user);
    $firstId = session()->getId();

    staffEmailLogin($this, $secondDevice, $user);
    $secondId = session()->getId();
    $oldToken = session()->token();
    $oldSecondCookies = $secondDevice;

    staffEmailRequest(
        $this,
        $secondDevice,
        'POST',
        'staff/email',
        staffEmailPayload(),
    )->assertOk();

    expect(session()->getId())->not->toBe($secondId);
    expect(session()->token())->not->toBe($oldToken);

    $this->assertDatabaseMissing('sessions', ['id' => $firstId]);
    $this->assertDatabaseMissing('sessions', ['id' => $secondId]);

    staffEmailRequest($this, $firstDevice, 'GET', 'staff/me')
        ->assertUnauthorized();

    staffEmailRequest($this, $secondDevice, 'GET', 'staff/me')
        ->assertUnauthorized();

    staffEmailRequest($this, $oldSecondCookies, 'GET', 'staff/me')
        ->assertUnauthorized();
})->with(['staff', 'admin']);

it('rejects login with the old email and permits login with the new unverified email', function (
    string $role
) {
    $user = staffEmailAccount(['role' => $role]);
    $oldEmail = $user->email;
    $cookies = [];

    staffEmailLogin($this, $cookies, $user);

    staffEmailRequest(
        $this,
        $cookies,
        'POST',
        'staff/email',
        staffEmailPayload(),
    )->assertOk();

    staffEmailRequest(
        $this,
        $cookies,
        'POST',
        'staff/login',
        [
            'email' => $oldEmail,
            'password' => 'StaffPassword@123',
        ],
    )->assertUnauthorized();

    staffEmailLogin($this, $cookies, $user->fresh());

    staffEmailRequest($this, $cookies, 'GET', 'staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', 'changed-staff@example.test');

    expect($user->fresh()->email_verified_at)->toBeNull();
})->with(['staff', 'admin']);

it('rejects a wrong current password without changing the account or sessions', function (
    string $role
) {
    assertStaffEmailRejected(
        $this,
        staffEmailPayload([
            'current_password' => 'WrongPassword@123',
        ]),
        'current_password',
        $role,
    );
})->with(['staff', 'admin']);

it('rejects missing required fields', function (string $field) {
    $payload = staffEmailPayload();
    unset($payload[$field]);

    assertStaffEmailRejected($this, $payload, $field);
})->with(['email', 'current_password']);

it('rejects invalid field values', function (string $field, mixed $value) {
    assertStaffEmailRejected(
        $this,
        staffEmailPayload([$field => $value]),
        $field,
    );
})->with([
    'null email' => ['email', null],
    'empty email' => ['email', ''],
    'whitespace email' => ['email', '   '],
    'invalid email' => ['email', 'not-an-email'],
    'array email' => ['email', ['changed@example.test']],
    'numeric email' => ['email', 123],
    'boolean email' => ['email', true],
    'email over 255 characters' => [
        'email',
        str_repeat('a', 64).'@'.
        str_repeat('b', 63).'.'.
        str_repeat('c', 63).'.'.
        str_repeat('d', 63).'.test',
    ],
    'SQL fragment email' => ['email', "' OR 1=1 --"],
    'null current password' => ['current_password', null],
    'empty current password' => ['current_password', ''],
    'array current password' => ['current_password', ['invalid']],
    'numeric current password' => ['current_password', 123],
    'boolean current password' => ['current_password', true],
    'current password over 72 ASCII bytes' => [
        'current_password', str_repeat('a', 73),
    ],
    'current password over 72 multibyte bytes' => [
        'current_password', 'Aa1!'.str_repeat('é', 35),
    ],
]);

it('rejects an email held by any existing account type', function (string $role) {
    User::factory()->create([
        'email' => 'taken@example.test',
        'role' => $role,
    ]);

    assertStaffEmailRejected(
        $this,
        staffEmailPayload(['email' => 'taken@example.test']),
        'email',
    );
})->with(['customer', 'staff', 'admin']);

it('rejects a duplicate email regardless of letter casing', function () {
    User::factory()->create([
        'email' => 'taken@example.test',
    ]);

    assertStaffEmailRejected(
        $this,
        staffEmailPayload(['email' => 'TAKEN@EXAMPLE.TEST']),
        'email',
    );
});

it('rejects unexpected fields and attempted account injection', function (
    string $field,
    mixed $value
) {
    assertStaffEmailRejected(
        $this,
        staffEmailPayload([$field => $value]),
        $field,
    );
})->with([
    'user id' => ['user_id', 999999],
    'role' => ['role', 'admin'],
    'permissions' => ['permissions', ['*']],
    'active flag' => ['is_active', true],
    'verification timestamp' => [
        'email_verified_at', '2026-01-01T00:00:00Z',
    ],
    'password update' => ['password', 'InjectedPassword@123'],
    'nested account' => ['account', ['role' => 'admin']],
    'arbitrary field' => ['unexpected', 'value'],
]);

it('accepts an existing legacy password as the current password', function () {
    $user = staffEmailAccount([
        'password' => Hash::make('abc'),
    ]);

    $cookies = [];

    staffEmailLogin($this, $cookies, $user, password: 'abc');

    staffEmailRequest(
        $this,
        $cookies,
        'POST',
        'staff/email',
        staffEmailPayload(['current_password' => 'abc']),
    )->assertOk();

    expect($user->fresh()->email)->toBe('changed-staff@example.test');
});

it('does not alter another account or revoke its session', function () {
    $user = staffEmailAccount();

    $other = staffEmailAccount([
        'email' => 'other-admin@example.test',
        'role' => 'admin',
    ]);

    $originalOther = $other->fresh()->getRawOriginal();
    $cookies = [];
    $otherCookies = [];

    staffEmailLogin($this, $cookies, $user);
    staffEmailLogin($this, $otherCookies, $other);

    staffEmailRequest(
        $this,
        $cookies,
        'POST',
        'staff/email',
        staffEmailPayload(),
    )->assertOk();

    expect($other->fresh()->getRawOriginal())->toBe($originalOther);

    staffEmailRequest($this, $otherCookies, 'GET', 'staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $other->email);
});

it('rejects unauthenticated requests', function () {
    $cookies = [];

    staffEmailRequest(
        $this,
        $cookies,
        'POST',
        'staff/email',
        staffEmailPayload(),
    )->assertUnauthorized();
});

it('rejects a customer session without changing the account', function () {
    $user = staffEmailAccount(['role' => 'customer']);

    DB::table('customers')->insert(['user_id' => $user->id]);

    $original = $user->fresh()->getRawOriginal();
    $cookies = [];

    staffEmailLogin($this, $cookies, $user, 'customer');

    staffEmailRequest(
        $this,
        $cookies,
        'POST',
        'staff/email',
        staffEmailPayload(),
    )->assertUnauthorized();

    expect($user->fresh()->getRawOriginal())->toBe($original);

    staffEmailRequest($this, $cookies, 'GET', 'customer/me')
        ->assertOk();
});

it('rejects a forged session cookie', function () {
    $cookies = [
        config('session.cookie') => 'attacker-controlled-cookie',
    ];

    staffEmailRequest(
        $this,
        $cookies,
        'POST',
        'staff/email',
        staffEmailPayload(),
    )->assertUnauthorized();
});

it('rejects an expired session without changing the account', function () {
    $user = staffEmailAccount();
    $original = $user->fresh()->getRawOriginal();
    $cookies = [];

    staffEmailLogin($this, $cookies, $user);

    $this->travel(121)->minutes();

    staffEmailRequest(
        $this,
        $cookies,
        'POST',
        'staff/email',
        staffEmailPayload(),
    )->assertUnauthorized();

    expect($user->fresh()->getRawOriginal())->toBe($original);
});

it('rejects a revoked session without changing the account', function () {
    $user = staffEmailAccount();
    $original = $user->fresh()->getRawOriginal();
    $cookies = [];

    staffEmailLogin($this, $cookies, $user);

    DB::table('sessions')->where('id', session()->getId())->delete();

    staffEmailRequest(
        $this,
        $cookies,
        'POST',
        'staff/email',
        staffEmailPayload(),
    )->assertUnauthorized();

    expect($user->fresh()->getRawOriginal())->toBe($original);
});

it('blocks email changes while disabled and preserves all own sessions', function (
    string $role
) {
    config(['auth.email_enabled' => false]);

    $user = staffEmailAccount(['role' => $role]);
    $original = $user->fresh()->getRawOriginal();

    $firstDevice = [];
    $secondDevice = [];

    staffEmailLogin($this, $firstDevice, $user);
    staffEmailLogin($this, $secondDevice, $user);

    staffEmailRequest(
        $this,
        $firstDevice,
        'POST',
        'staff/email',
        staffEmailPayload(),
    )->assertForbidden();

    expect($user->fresh()->getRawOriginal())->toBe($original);

    staffEmailRequest($this, $firstDevice, 'GET', 'staff/me')
        ->assertOk();

    staffEmailRequest($this, $secondDevice, 'GET', 'staff/me')
        ->assertOk();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
})->with(['staff', 'admin']);

it('does not expose the password or stored password hash in a successful response', function () {
    $user = staffEmailAccount();
    $cookies = [];

    staffEmailLogin($this, $cookies, $user);

    $response = staffEmailRequest(
        $this,
        $cookies,
        'POST',
        'staff/email',
        staffEmailPayload(),
    );

    $response->assertOk();

    expect($response->getContent())
        ->not->toContain('StaffPassword@123')
        ->not->toContain($user->password);
});
