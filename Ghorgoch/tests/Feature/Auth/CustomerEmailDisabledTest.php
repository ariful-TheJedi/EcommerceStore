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
        'auth.email_enabled' => false,
        'session.driver' => 'database',
        'session.lottery' => [0, 100],
    ]);

    Mail::fake();
    Notification::fake();
});

function customerEmailDisabledAccount(array $overrides = []): User
{
    $user = User::factory()->create(array_replace([
        'email' => 'customer-email@example.test',
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

function customerEmailDisabledPayload(array $overrides = []): array
{
    return array_replace([
        'email' => 'changed-customer@example.test',
        'current_password' => 'CustomerPassword@123',
    ], $overrides);
}

/**
 * Reload session/authentication services between requests.
 * CSRF enforcement is covered separately.
 */
function customerEmailDisabledRequest(
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

function customerEmailDisabledLogin(
    TestCase $test,
    array &$cookies,
    User $user,
    string $type = 'customer'
): void {
    customerEmailDisabledRequest(
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

it('returns JSON forbidden to an authenticated customer while email changes are disabled', function () {
    $user = customerEmailDisabledAccount();
    $cookies = [];

    customerEmailDisabledLogin($this, $cookies, $user);

    $response = customerEmailDisabledRequest(
        $this,
        $cookies,
        'POST',
        'customer/email',
        customerEmailDisabledPayload(),
    );

    $response
        ->assertForbidden()
        ->assertJsonStructure(['message', 'data']);

    expect(strtolower(
        $response->headers->get('Content-Type', '')
    ))->toStartWith('application/json');

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
});

it('preserves the email password role and verification state', function () {
    $user = customerEmailDisabledAccount();
    $original = $user->fresh()->getRawOriginal();
    $cookies = [];

    customerEmailDisabledLogin($this, $cookies, $user);

    customerEmailDisabledRequest(
        $this,
        $cookies,
        'POST',
        'customer/email',
        customerEmailDisabledPayload(),
    )->assertForbidden();

    expect($user->fresh()->getRawOriginal())->toBe($original);

    $this->assertDatabaseMissing('users', [
        'email' => 'changed-customer@example.test',
    ]);
});

it('preserves all existing customer sessions after a blocked email change', function () {
    $user = customerEmailDisabledAccount();

    $firstDevice = [];
    $secondDevice = [];

    customerEmailDisabledLogin($this, $firstDevice, $user);
    $firstId = session()->getId();

    customerEmailDisabledLogin($this, $secondDevice, $user);
    $secondId = session()->getId();

    customerEmailDisabledRequest(
        $this,
        $firstDevice,
        'POST',
        'customer/email',
        customerEmailDisabledPayload(),
    )->assertForbidden();

    $this->assertDatabaseHas('sessions', ['id' => $firstId]);
    $this->assertDatabaseHas('sessions', ['id' => $secondId]);

    customerEmailDisabledRequest(
        $this, $firstDevice, 'GET', 'customer/me'
    )
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email);

    customerEmailDisabledRequest(
        $this, $secondDevice, 'GET', 'customer/me'
    )
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email);
});

it('preserves existing reset and verification tokens', function () {
    $user = customerEmailDisabledAccount();

    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => Hash::make(str_repeat('a', 64)),
        'created_at' => now(),
    ]);

    DB::table('email_verification_tokens')->insert([
        'user_id' => $user->id,
        'token_hash' => Hash::make(str_repeat('b', 64)),
        'expires_at' => now()->addHour(),
        'created_at' => now(),
    ]);

    $resetBefore = (array) DB::table('password_reset_tokens')
        ->where('email', $user->email)
        ->first();

    $verificationBefore = (array) DB::table('email_verification_tokens')
        ->where('user_id', $user->id)
        ->first();

    $cookies = [];

    customerEmailDisabledLogin($this, $cookies, $user);

    customerEmailDisabledRequest(
        $this,
        $cookies,
        'POST',
        'customer/email',
        customerEmailDisabledPayload(),
    )->assertForbidden();

    expect((array) DB::table('password_reset_tokens')
        ->where('email', $user->email)
        ->first())->toBe($resetBefore);

    expect((array) DB::table('email_verification_tokens')
        ->where('user_id', $user->id)
        ->first())->toBe($verificationBefore);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
});

it('also blocks an active unverified customer without changing their account', function () {
    $user = customerEmailDisabledAccount([
        'email_verified_at' => null,
    ]);

    $original = $user->fresh()->getRawOriginal();
    $cookies = [];

    customerEmailDisabledLogin($this, $cookies, $user);

    customerEmailDisabledRequest(
        $this,
        $cookies,
        'POST',
        'customer/email',
        customerEmailDisabledPayload(),
    )->assertForbidden();

    expect($user->fresh()->getRawOriginal())->toBe($original);
});

it('requires authentication before accessing the disabled account feature', function () {
    $cookies = [];

    customerEmailDisabledRequest(
        $this,
        $cookies,
        'POST',
        'customer/email',
        customerEmailDisabledPayload(),
    )->assertUnauthorized();
});

it('rejects a forged session cookie', function () {
    $cookies = [
        config('session.cookie') => 'attacker-controlled-cookie',
    ];

    customerEmailDisabledRequest(
        $this,
        $cookies,
        'POST',
        'customer/email',
        customerEmailDisabledPayload(),
    )->assertUnauthorized();
});

it('rejects staff and admin sessions without modifying their accounts or sessions', function (
    string $role
) {
    $user = User::factory()->create([
        'email' => 'wrong-type-email@example.test',
        'password' => Hash::make('CustomerPassword@123'),
        'role' => $role,
        'is_active' => true,
    ]);

    $original = $user->fresh()->getRawOriginal();
    $cookies = [];

    customerEmailDisabledLogin($this, $cookies, $user, 'staff');

    customerEmailDisabledRequest(
        $this,
        $cookies,
        'POST',
        'customer/email',
        customerEmailDisabledPayload(),
    )->assertUnauthorized();

    expect($user->fresh()->getRawOriginal())->toBe($original);

    customerEmailDisabledRequest(
        $this, $cookies, 'GET', 'staff/me'
    )->assertOk();
})->with(['staff', 'admin']);

it('does not expose the submitted current password', function () {
    $user = customerEmailDisabledAccount();
    $cookies = [];

    customerEmailDisabledLogin($this, $cookies, $user);

    $response = customerEmailDisabledRequest(
        $this,
        $cookies,
        'POST',
        'customer/email',
        customerEmailDisabledPayload(),
    );

    $response->assertForbidden();

    expect($response->getContent())
        ->not->toContain('CustomerPassword@123')
        ->not->toContain($user->password);
});

it('does not create extra account or customer records', function () {
    $user = customerEmailDisabledAccount();

    $usersBefore = DB::table('users')->count();
    $customersBefore = DB::table('customers')->count();

    $cookies = [];

    customerEmailDisabledLogin($this, $cookies, $user);

    customerEmailDisabledRequest(
        $this,
        $cookies,
        'POST',
        'customer/email',
        customerEmailDisabledPayload(),
    )->assertForbidden();

    expect(DB::table('users')->count())->toBe($usersBefore);
    expect(DB::table('customers')->count())->toBe($customersBefore);
});
