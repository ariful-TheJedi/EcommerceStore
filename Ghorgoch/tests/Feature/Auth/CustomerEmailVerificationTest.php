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

function customerVerificationAccount(array $overrides = []): User
{
    $user = User::factory()->create(array_replace([
        'email' => 'customer-verify@example.test',
        'password' => Hash::make('CustomerPassword@123'),
        'role' => 'customer',
        'is_active' => true,
        'email_verified_at' => null,
    ], $overrides));

    DB::table('customers')->insert(['user_id' => $user->id]);

    return $user;
}

function customerVerificationToken(
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
 * Independently reload session/authentication state on each request.
 * CSRF enforcement is covered separately.
 */
function customerVerificationRequest(
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

function customerVerificationLogin(
    TestCase $test,
    array &$cookies,
    User $user,
    string $type = 'customer'
): void {
    customerVerificationRequest(
        $test,
        $cookies,
        'POST',
        $type.'/login',
        [
            'email' => $user->email,
            'password' => 'CustomerPassword@123',
        ],
    )->assertOk();
}

it('verifies the authenticated customers email using a valid token', function () {
    $user = customerVerificationAccount();
    $token = customerVerificationToken($user);
    $cookies = [];

    customerVerificationLogin($this, $cookies, $user);

    $response = customerVerificationRequest(
        $this,
        $cookies,
        'POST',
        'customer/email/verify',
        ['token' => $token],
    );

    $response->assertOk();

    expect($user->fresh()->email_verified_at)->not->toBeNull();

    $this->assertDatabaseMissing('email_verification_tokens', [
        'user_id' => $user->id,
    ]);

    expect($response->getContent())->not->toContain($token);

    customerVerificationRequest(
        $this, $cookies, 'GET', 'customer/me'
    )->assertOk();
});

it('preserves account credentials and privileges when verification succeeds', function () {
    $user = customerVerificationAccount();
    $originalHash = $user->password;
    $originalEmail = $user->email;
    $originalName = $user->name;

    $token = customerVerificationToken($user);
    $cookies = [];

    customerVerificationLogin($this, $cookies, $user);

    customerVerificationRequest(
        $this,
        $cookies,
        'POST',
        'customer/email/verify',
        ['token' => $token],
    )->assertOk();

    $user->refresh();

    expect($user->password)->toBe($originalHash);
    expect($user->email)->toBe($originalEmail);
    expect($user->name)->toBe($originalName);
    expect($user->role)->toBe('customer');
    expect($user->is_active)->toBeTrue();
});

it('rejects an incorrect token without consuming the valid token', function () {
    $user = customerVerificationAccount();
    $validToken = customerVerificationToken($user);
    $cookies = [];

    customerVerificationLogin($this, $cookies, $user);

    customerVerificationRequest(
        $this,
        $cookies,
        'POST',
        'customer/email/verify',
        ['token' => str_repeat('z', 64)],
    )->assertUnprocessable();

    expect($user->fresh()->email_verified_at)->toBeNull();

    $this->assertDatabaseHas('email_verification_tokens', [
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $validToken.'|'.$user->email),
    ]);

    customerVerificationRequest(
        $this,
        $cookies,
        'POST',
        'customer/email/verify',
        ['token' => $validToken],
    )->assertOk();
});

it('rejects a token when no verification record exists', function () {
    $user = customerVerificationAccount();
    $cookies = [];

    customerVerificationLogin($this, $cookies, $user);

    customerVerificationRequest(
        $this,
        $cookies,
        'POST',
        'customer/email/verify',
        ['token' => str_repeat('a', 64)],
    )->assertUnprocessable();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('rejects an expired token', function () {
    $user = customerVerificationAccount();
    $token = customerVerificationToken($user, -1);
    $cookies = [];

    customerVerificationLogin($this, $cookies, $user);

    customerVerificationRequest(
        $this,
        $cookies,
        'POST',
        'customer/email/verify',
        ['token' => $token],
    )->assertUnprocessable();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('accepts a token before its sixty minute expiry', function () {
    $user = customerVerificationAccount();
    $token = customerVerificationToken($user);
    $cookies = [];

    customerVerificationLogin($this, $cookies, $user);

    $this->travel(59)->minutes();

    customerVerificationRequest(
        $this,
        $cookies,
        'POST',
        'customer/email/verify',
        ['token' => $token],
    )->assertOk();
});

it('rejects a token after its sixty minute expiry', function () {
    $user = customerVerificationAccount();
    $token = customerVerificationToken($user);
    $cookies = [];

    customerVerificationLogin($this, $cookies, $user);

    $this->travel(61)->minutes();

    customerVerificationRequest(
        $this,
        $cookies,
        'POST',
        'customer/email/verify',
        ['token' => $token],
    )->assertUnprocessable();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('does not accept another customers token or consume it', function () {
    $user = customerVerificationAccount();

    $other = customerVerificationAccount([
        'email' => 'other-verify@example.test',
    ]);

    $otherToken = customerVerificationToken($other);
    $cookies = [];

    customerVerificationLogin($this, $cookies, $user);

    customerVerificationRequest(
        $this,
        $cookies,
        'POST',
        'customer/email/verify',
        ['token' => $otherToken],
    )->assertUnprocessable();

    expect($user->fresh()->email_verified_at)->toBeNull();
    expect($other->fresh()->email_verified_at)->toBeNull();

    $this->assertDatabaseHas('email_verification_tokens', [
        'user_id' => $other->id,
        'token_hash' => hash('sha256', $otherToken.'|'.$other->email),
    ]);
});

it('rejects a used token after the account becomes unverified again', function () {
    $user = customerVerificationAccount();
    $token = customerVerificationToken($user);
    $cookies = [];

    customerVerificationLogin($this, $cookies, $user);

    customerVerificationRequest(
        $this,
        $cookies,
        'POST',
        'customer/email/verify',
        ['token' => $token],
    )->assertOk();

    // Explicit fixture change: verify the consumed token cannot be reused.
    DB::table('users')->where('id', $user->id)->update([
        'email_verified_at' => null,
    ]);

    customerVerificationRequest(
        $this,
        $cookies,
        'POST',
        'customer/email/verify',
        ['token' => $token],
    )->assertUnprocessable();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('rejects an old email token after the email changes', function () {
    $user = customerVerificationAccount();
    $token = customerVerificationToken($user);

    $user->email = 'new-verify@example.test';
    $user->save();

    $cookies = [];

    customerVerificationLogin($this, $cookies, $user->fresh());

    customerVerificationRequest(
        $this,
        $cookies,
        'POST',
        'customer/email/verify',
        ['token' => $token],
    )->assertUnprocessable();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('rejects missing or malformed tokens without verifying the account', function (
    array $payload
) {
    $user = customerVerificationAccount();
    customerVerificationToken($user);

    $cookies = [];

    customerVerificationLogin($this, $cookies, $user);

    customerVerificationRequest(
        $this,
        $cookies,
        'POST',
        'customer/email/verify',
        $payload,
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['token']);

    expect($user->fresh()->email_verified_at)->toBeNull();
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
    $user = customerVerificationAccount();
    $token = customerVerificationToken($user);
    $cookies = [];

    customerVerificationLogin($this, $cookies, $user);

    customerVerificationRequest(
        $this,
        $cookies,
        'POST',
        'customer/email/verify',
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

it('requires authentication even when a valid verification token is supplied', function () {
    $user = customerVerificationAccount();
    $token = customerVerificationToken($user);
    $cookies = [];

    customerVerificationRequest(
        $this,
        $cookies,
        'POST',
        'customer/email/verify',
        ['token' => $token],
    )->assertUnauthorized();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('rejects staff and admin sessions', function (string $role) {
    $customer = customerVerificationAccount();
    $token = customerVerificationToken($customer);

    $staff = User::factory()->create([
        'email' => 'verification-staff@example.test',
        'password' => Hash::make('CustomerPassword@123'),
        'role' => $role,
        'is_active' => true,
    ]);

    $cookies = [];

    customerVerificationLogin($this, $cookies, $staff, 'staff');

    customerVerificationRequest(
        $this,
        $cookies,
        'POST',
        'customer/email/verify',
        ['token' => $token],
    )->assertUnauthorized();

    expect($customer->fresh()->email_verified_at)->toBeNull();

    customerVerificationRequest(
        $this, $cookies, 'GET', 'staff/me'
    )->assertOk();
})->with(['staff', 'admin']);

it('blocks verification while disabled without consuming the token or ending the session', function () {
    config(['auth.email_enabled' => false]);

    $user = customerVerificationAccount();
    $token = customerVerificationToken($user);
    $cookies = [];

    customerVerificationLogin($this, $cookies, $user);

    customerVerificationRequest(
        $this,
        $cookies,
        'POST',
        'customer/email/verify',
        ['token' => $token],
    )->assertForbidden();

    expect($user->fresh()->email_verified_at)->toBeNull();

    $this->assertDatabaseHas('email_verification_tokens', [
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $token.'|'.$user->email),
    ]);

    customerVerificationRequest(
        $this, $cookies, 'GET', 'customer/me'
    )->assertOk();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
});
