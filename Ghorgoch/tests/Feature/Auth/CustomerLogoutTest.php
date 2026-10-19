<?php

use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    expect(app()->environment())->toBe('testing');
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');

    config([
        'auth.customer_enabled' => true,
        'session.driver' => 'database',
        'session.lottery' => [0, 100],
    ]);
});

function customerLogoutAccount(array $overrides = []): User
{
    $user = User::factory()->create(array_replace([
        'email' => 'customer-logout@example.test',
        'password' => Hash::make('CustomerPassword@123'),
        'role' => 'customer',
        'is_active' => true,
    ], $overrides));

    DB::table('customers')->insert([
        'user_id' => $user->id,
    ]);

    return $user;
}

/**
 * Reload authentication/session services and replay raw response cookies.
 * CSRF enforcement is tested separately in CsrfProtectionTest.php.
 */
function customerLogoutRequest(
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

function customerLogoutLogin(
    TestCase $test,
    array &$cookies,
    User $user,
    string $type = 'customer'
): void {
    customerLogoutRequest(
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

it('logs out the current customer session', function () {
    $user = customerLogoutAccount();
    $cookies = [];

    customerLogoutLogin($this, $cookies, $user);

    customerLogoutRequest($this, $cookies, 'POST', 'customer/logout')
        ->assertOk()
        ->assertJsonStructure(['message', 'data']);

    customerLogoutRequest($this, $cookies, 'GET', 'customer/me')
        ->assertUnauthorized();
});

it('removes the authenticated session and rotates its session id and csrf token', function () {
    $user = customerLogoutAccount();
    $cookies = [];

    customerLogoutLogin($this, $cookies, $user);

    $oldSessionId = session()->getId();
    $oldToken = session()->token();

    $this->assertDatabaseHas('sessions', ['id' => $oldSessionId]);

    customerLogoutRequest($this, $cookies, 'POST', 'customer/logout')
        ->assertOk();

    expect(session()->getId())->not->toBe($oldSessionId);
    expect(session()->token())->not->toBe($oldToken);
    expect(session()->token())->toBeString()->not->toBe('');

    $this->assertDatabaseMissing('sessions', ['id' => $oldSessionId]);
});

it('does not accept a replay of the old authenticated cookie', function () {
    $user = customerLogoutAccount();
    $cookies = [];

    customerLogoutLogin($this, $cookies, $user);

    $oldCookies = $cookies;

    customerLogoutRequest($this, $cookies, 'POST', 'customer/logout')
        ->assertOk();

    customerLogoutRequest($this, $oldCookies, 'GET', 'customer/me')
        ->assertUnauthorized();

    customerLogoutRequest($this, $oldCookies, 'POST', 'customer/logout')
        ->assertUnauthorized();
});

it('preserves another session belonging to the same customer', function () {
    $user = customerLogoutAccount();

    $firstDevice = [];
    $secondDevice = [];

    customerLogoutLogin($this, $firstDevice, $user);
    $firstSessionId = session()->getId();

    customerLogoutLogin($this, $secondDevice, $user);
    $secondSessionId = session()->getId();

    expect($firstSessionId)->not->toBe($secondSessionId);

    customerLogoutRequest($this, $firstDevice, 'POST', 'customer/logout')
        ->assertOk();

    $this->assertDatabaseMissing('sessions', ['id' => $firstSessionId]);
    $this->assertDatabaseHas('sessions', ['id' => $secondSessionId]);

    customerLogoutRequest($this, $firstDevice, 'GET', 'customer/me')
        ->assertUnauthorized();

    customerLogoutRequest($this, $secondDevice, 'GET', 'customer/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email);
});

it('does not log out another customer', function () {
    $first = customerLogoutAccount([
        'email' => 'first-logout@example.test',
    ]);

    $second = customerLogoutAccount([
        'email' => 'second-logout@example.test',
    ]);

    $firstCookies = [];
    $secondCookies = [];

    customerLogoutLogin($this, $firstCookies, $first);
    customerLogoutLogin($this, $secondCookies, $second);

    customerLogoutRequest($this, $firstCookies, 'POST', 'customer/logout')
        ->assertOk();

    customerLogoutRequest($this, $secondCookies, 'GET', 'customer/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $second->email);
});

it('rejects logout without authentication', function () {
    $cookies = [];

    customerLogoutRequest($this, $cookies, 'POST', 'customer/logout')
        ->assertUnauthorized();
});

it('rejects a forged session cookie', function () {
    $cookies = [
        config('session.cookie') => 'attacker-controlled-cookie',
    ];

    customerLogoutRequest($this, $cookies, 'POST', 'customer/logout')
        ->assertUnauthorized();
});

it('rejects staff and admin sessions without logging them out', function (
    string $role
) {
    $user = User::factory()->create([
        'email' => 'staff-logout@example.test',
        'password' => Hash::make('CustomerPassword@123'),
        'role' => $role,
        'is_active' => true,
    ]);

    $cookies = [];

    customerLogoutLogin($this, $cookies, $user, 'staff');

    customerLogoutRequest($this, $cookies, 'POST', 'customer/logout')
        ->assertUnauthorized();

    customerLogoutRequest($this, $cookies, 'GET', 'staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email);
})->with(['staff', 'admin']);

it('rejects unexpected inputs without ending the valid session', function (
    string $field,
    mixed $value
) {
    $user = customerLogoutAccount();
    $cookies = [];

    customerLogoutLogin($this, $cookies, $user);

    customerLogoutRequest(
        $this,
        $cookies,
        'POST',
        'customer/logout',
        [$field => $value],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    customerLogoutRequest($this, $cookies, 'GET', 'customer/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email);
})->with([
    'another user id' => ['user_id', 999999],
    'role' => ['role', 'admin'],
    'permissions' => ['permissions', ['*']],
    'active flag' => ['is_active', true],
    'all devices flag' => ['all_devices', true],
    'session selector' => ['session_id', 'another-session'],
    'arbitrary field' => ['unexpected', 'value'],
]);

it('does not modify account credentials or delete customer records', function () {
    $user = customerLogoutAccount();

    $originalHash = $user->password;
    $usersBefore = DB::table('users')->count();
    $customersBefore = DB::table('customers')->count();

    $cookies = [];

    customerLogoutLogin($this, $cookies, $user);

    customerLogoutRequest($this, $cookies, 'POST', 'customer/logout')
        ->assertOk();

    $user->refresh();

    expect($user->password)->toBe($originalHash);
    expect($user->role)->toBe('customer');
    expect($user->is_active)->toBeTrue();

    expect(DB::table('users')->count())->toBe($usersBefore);
    expect(DB::table('customers')->count())->toBe($customersBefore);
});

it('allows a fresh login after logout', function () {
    $user = customerLogoutAccount();
    $cookies = [];

    customerLogoutLogin($this, $cookies, $user);

    customerLogoutRequest($this, $cookies, 'POST', 'customer/logout')
        ->assertOk();

    customerLogoutLogin($this, $cookies, $user);

    customerLogoutRequest($this, $cookies, 'GET', 'customer/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email);
});
