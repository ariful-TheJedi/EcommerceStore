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

function staffVerificationAccount(array $overrides = []): User
{
    return User::factory()->create(array_replace([
        'email' => 'staff-verify@example.test',
        'password' => Hash::make('StaffPassword@123'),
        'role' => 'staff',
        'is_active' => true,
        'email_verified_at' => null,
    ], $overrides));
}

function staffVerificationToken(
    User $user,
    int $expiresInMinutes = 60
): string {
    $token = bin2hex(random_bytes(32));

    DB::table('email_verification_tokens')->insert([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $token.'|'.$user->email),
        'expires_at' => now()->addMinutes($expiresInMinutes),
        'created_at' => now(),
    ]);

    return $token;
}

/**
 * Reload authentication/session state and replay raw response cookies.
 * CSRF enforcement is covered separately.
 */
function staffVerificationRequest(
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

function staffVerificationLogin(
    TestCase $test,
    array &$cookies,
    User $user,
    string $type = 'staff'
): void {
    staffVerificationRequest(
        $test,
        $cookies,
        'POST',
        $type.'/login',
        [
            'email' => $user->email,
            'password' => 'StaffPassword@123',
        ],
    )->assertOk();
}

it('verifies an authenticated staff or admin email with a valid token', function (
    string $role
) {
    $user = staffVerificationAccount(['role' => $role]);
    $token = staffVerificationToken($user);
    $cookies = [];

    staffVerificationLogin($this, $cookies, $user);

    $response = staffVerificationRequest(
        $this,
        $cookies,
        'POST',
        'staff/email/verify',
        ['token' => $token],
    );

    $response->assertOk();

    expect($user->fresh()->email_verified_at)->not->toBeNull();

    $this->assertDatabaseMissing('email_verification_tokens', [
        'user_id' => $user->id,
    ]);

    expect($response->getContent())->not->toContain($token);

    staffVerificationRequest($this, $cookies, 'GET', 'staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.role', $role);
})->with(['staff', 'admin']);

it('preserves account credentials privileges and records', function (string $role) {
    $user = staffVerificationAccount(['role' => $role]);

    $originalHash = $user->password;
    $originalEmail = $user->email;
    $originalName = $user->name;

    $usersBefore = DB::table('users')->count();
    $customersBefore = DB::table('customers')->count();

    $token = staffVerificationToken($user);
    $cookies = [];

    staffVerificationLogin($this, $cookies, $user);

    staffVerificationRequest(
        $this,
        $cookies,
        'POST',
        'staff/email/verify',
        ['token' => $token],
    )->assertOk();

    $user->refresh();

    expect($user->password)->toBe($originalHash);
    expect($user->email)->toBe($originalEmail);
    expect($user->name)->toBe($originalName);
    expect($user->role)->toBe($role);
    expect($user->is_active)->toBeTrue();

    expect(DB::table('users')->count())->toBe($usersBefore);
    expect(DB::table('customers')->count())->toBe($customersBefore);
})->with(['staff', 'admin']);

it('rejects an incorrect token without consuming the valid one', function (
    string $role
) {
    $user = staffVerificationAccount(['role' => $role]);
    $token = staffVerificationToken($user);
    $cookies = [];

    staffVerificationLogin($this, $cookies, $user);

    staffVerificationRequest(
        $this,
        $cookies,
        'POST',
        'staff/email/verify',
        ['token' => str_repeat('z', 64)],
    )->assertUnprocessable();

    expect($user->fresh()->email_verified_at)->toBeNull();

    $this->assertDatabaseHas('email_verification_tokens', [
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $token.'|'.$user->email),
    ]);

    staffVerificationRequest(
        $this,
        $cookies,
        'POST',
        'staff/email/verify',
        ['token' => $token],
    )->assertOk();
})->with(['staff', 'admin']);

it('rejects a token when no verification record exists', function () {
    $user = staffVerificationAccount();
    $cookies = [];

    staffVerificationLogin($this, $cookies, $user);

    staffVerificationRequest(
        $this,
        $cookies,
        'POST',
        'staff/email/verify',
        ['token' => str_repeat('a', 64)],
    )->assertUnprocessable();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('enforces token expiry', function (
    string $role,
    int $elapsedMinutes,
    int $expectedStatus
) {
    $user = staffVerificationAccount(['role' => $role]);
    $token = staffVerificationToken($user);
    $cookies = [];

    staffVerificationLogin($this, $cookies, $user);

    $this->travel($elapsedMinutes)->minutes();

    staffVerificationRequest(
        $this,
        $cookies,
        'POST',
        'staff/email/verify',
        ['token' => $token],
    )->assertStatus($expectedStatus);

    if ($expectedStatus === 200) {
        expect($user->fresh()->email_verified_at)->not->toBeNull();
    } else {
        expect($user->fresh()->email_verified_at)->toBeNull();
    }
})->with(['staff', 'admin'])->with([
    'before expiry' => [59, 200],
    'after expiry' => [61, 422],
]);

it('rejects another staff or admin accounts token without consuming it', function (
    string $role,
    string $otherRole
) {
    $user = staffVerificationAccount(['role' => $role]);

    $other = staffVerificationAccount([
        'email' => 'other-staff-verify@example.test',
        'role' => $otherRole,
    ]);

    $token = staffVerificationToken($other);
    $cookies = [];

    staffVerificationLogin($this, $cookies, $user);

    staffVerificationRequest(
        $this,
        $cookies,
        'POST',
        'staff/email/verify',
        ['token' => $token],
    )->assertUnprocessable();

    expect($user->fresh()->email_verified_at)->toBeNull();
    expect($other->fresh()->email_verified_at)->toBeNull();

    $this->assertDatabaseHas('email_verification_tokens', [
        'user_id' => $other->id,
        'token_hash' => hash('sha256', $token.'|'.$other->email),
    ]);
})->with([
    'staff using staff token' => ['staff', 'staff'],
    'staff using admin token' => ['staff', 'admin'],
    'admin using staff token' => ['admin', 'staff'],
    'admin using admin token' => ['admin', 'admin'],
]);

it('rejects a customer token even through an authenticated staff session', function () {
    $user = staffVerificationAccount();

    $customer = staffVerificationAccount([
        'email' => 'customer-token@example.test',
        'role' => 'customer',
    ]);

    DB::table('customers')->insert(['user_id' => $customer->id]);

    $token = staffVerificationToken($customer);
    $cookies = [];

    staffVerificationLogin($this, $cookies, $user);

    staffVerificationRequest(
        $this,
        $cookies,
        'POST',
        'staff/email/verify',
        ['token' => $token],
    )->assertUnprocessable();

    expect($user->fresh()->email_verified_at)->toBeNull();
    expect($customer->fresh()->email_verified_at)->toBeNull();

    $this->assertDatabaseHas('email_verification_tokens', [
        'user_id' => $customer->id,
        'token_hash' => hash('sha256', $token.'|'.$customer->email),
    ]);
});

it('rejects a consumed token if the account becomes unverified again', function (
    string $role
) {
    $user = staffVerificationAccount(['role' => $role]);
    $token = staffVerificationToken($user);
    $cookies = [];

    staffVerificationLogin($this, $cookies, $user);

    staffVerificationRequest(
        $this,
        $cookies,
        'POST',
        'staff/email/verify',
        ['token' => $token],
    )->assertOk();

    // Fixture change to check that a consumed token cannot verify again.
    DB::table('users')->where('id', $user->id)->update([
        'email_verified_at' => null,
    ]);

    staffVerificationRequest(
        $this,
        $cookies,
        'POST',
        'staff/email/verify',
        ['token' => $token],
    )->assertUnprocessable();

    expect($user->fresh()->email_verified_at)->toBeNull();
})->with(['staff', 'admin']);

it('rejects an old email token after the account email changes', function (
    string $role
) {
    $user = staffVerificationAccount(['role' => $role]);
    $token = staffVerificationToken($user);

    $user->email = 'new-staff-verify@example.test';
    $user->save();

    $cookies = [];

    staffVerificationLogin($this, $cookies, $user->fresh());

    staffVerificationRequest(
        $this,
        $cookies,
        'POST',
        'staff/email/verify',
        ['token' => $token],
    )->assertUnprocessable();

    expect($user->fresh()->email_verified_at)->toBeNull();
})->with(['staff', 'admin']);

it('rejects missing or malformed tokens', function (array $payload) {
    $user = staffVerificationAccount();
    customerVerificationTokenForStaffTest:
    $token = staffVerificationToken($user);
    $cookies = [];

    staffVerificationLogin($this, $cookies, $user);

    staffVerificationRequest(
        $this,
        $cookies,
        'POST',
        'staff/email/verify',
        $payload,
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['token']);

    expect($user->fresh()->email_verified_at)->toBeNull();

    $this->assertDatabaseHas('email_verification_tokens', [
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $token.'|'.$user->email),
    ]);
})->with([
    'missing' => [[]],
    'null' => [['token' => null]],
    'empty' => [['token' => '']],
    'array' => [['token' => ['invalid']]],
    'number' => [['token' => 123]],
    'boolean' => [['token' => true]],
    '63 characters' => [['token' => str_repeat('a', 63)]],
    '65 characters' => [['token' => str_repeat('a', 65)]],
]);

it('rejects unexpected fields without consuming the token', function (
    string $field,
    mixed $value
) {
    $user = staffVerificationAccount();
    $token = staffVerificationToken($user);
    $cookies = [];

    staffVerificationLogin($this, $cookies, $user);

    staffVerificationRequest(
        $this,
        $cookies,
        'POST',
        'staff/email/verify',
        ['token' => $token, $field => $value],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect($user->fresh()->email_verified_at)->toBeNull();

    $this->assertDatabaseHas('email_verification_tokens', [
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $token.'|'.$user->email),
    ]);
})->with([
    'user id' => ['user_id', 999999],
    'email' => ['email', 'another@example.test'],
    'role' => ['role', 'admin'],
    'permissions' => ['permissions', ['*']],
    'active flag' => ['is_active', true],
    'verification timestamp' => [
        'email_verified_at', '2026-01-01T00:00:00Z',
    ],
]);

it('requires authentication even with a valid token', function () {
    $user = staffVerificationAccount();
    $token = staffVerificationToken($user);
    $cookies = [];

    staffVerificationRequest(
        $this,
        $cookies,
        'POST',
        'staff/email/verify',
        ['token' => $token],
    )->assertUnauthorized();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('rejects a customer session without verifying either account', function () {
    $staff = staffVerificationAccount();
    $token = staffVerificationToken($staff);

    $customer = staffVerificationAccount([
        'email' => 'customer-session@example.test',
        'role' => 'customer',
    ]);

    DB::table('customers')->insert(['user_id' => $customer->id]);

    $cookies = [];

    staffVerificationLogin($this, $cookies, $customer, 'customer');

    staffVerificationRequest(
        $this,
        $cookies,
        'POST',
        'staff/email/verify',
        ['token' => $token],
    )->assertUnauthorized();

    expect($staff->fresh()->email_verified_at)->toBeNull();
    expect($customer->fresh()->email_verified_at)->toBeNull();

    staffVerificationRequest($this, $cookies, 'GET', 'customer/me')
        ->assertOk();
});

it('rejects an expired login session without consuming the token', function () {
    $user = staffVerificationAccount();
    $cookies = [];

    staffVerificationLogin($this, $cookies, $user);

    $this->travel(121)->minutes();

    // Fresh token isolates session expiry from token expiry.
    $token = staffVerificationToken($user);

    staffVerificationRequest(
        $this,
        $cookies,
        'POST',
        'staff/email/verify',
        ['token' => $token],
    )->assertUnauthorized();

    expect($user->fresh()->email_verified_at)->toBeNull();

    $this->assertDatabaseHas('email_verification_tokens', [
        'user_id' => $user->id,
    ]);
});

it('blocks verification while disabled without consuming the token or ending the session', function (
    string $role
) {
    config(['auth.email_enabled' => false]);

    $user = staffVerificationAccount(['role' => $role]);
    $token = staffVerificationToken($user);
    $cookies = [];

    staffVerificationLogin($this, $cookies, $user);

    staffVerificationRequest(
        $this,
        $cookies,
        'POST',
        'staff/email/verify',
        ['token' => $token],
    )->assertForbidden();

    expect($user->fresh()->email_verified_at)->toBeNull();

    $this->assertDatabaseHas('email_verification_tokens', [
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $token.'|'.$user->email),
    ]);

    staffVerificationRequest($this, $cookies, 'GET', 'staff/me')
        ->assertOk();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
})->with(['staff', 'admin']);
