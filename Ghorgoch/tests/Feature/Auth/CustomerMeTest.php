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

afterEach(function () {
    $this->travelBack();
});

function customerMeAccount(array $overrides = []): User
{
    $user = User::factory()->create(array_replace([
        'email' => 'customer-me@example.test',
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
 * Replay raw response cookies and reset cached authentication/session
 * services so each call independently loads its session.
 */
function customerMeRequest(
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

function customerMeLogin(
    TestCase $test,
    array &$cookies,
    User $user,
    string $type = 'customer'
): void {
    customerMeRequest(
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

it('returns the logged in customers own account', function () {
    $user = customerMeAccount();
    $cookies = [];

    customerMeLogin($this, $cookies, $user);

    $response = customerMeRequest(
        $this, $cookies, 'GET', 'customer/me'
    );

    $response
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.user.role', 'customer');

    expect((string) $response->json('data.user.id'))
        ->toBe((string) $user->id);
});

it('allows an active unverified customer to read their account', function () {
    $user = customerMeAccount(['email_verified_at' => null]);
    $cookies = [];

    customerMeLogin($this, $cookies, $user);

    customerMeRequest($this, $cookies, 'GET', 'customer/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email);
});

it('rejects requests without a session', function () {
    $cookies = [];

    customerMeRequest($this, $cookies, 'GET', 'customer/me')
        ->assertUnauthorized()
        ->assertJsonStructure(['message', 'data']);
});

it('does not authenticate using a bearer token alone', function () {
    $this->withHeaders([
        'Authorization' => 'Bearer attacker-controlled-token',
    ])
        ->getJson('/api/auth/customer/me')
        ->assertUnauthorized();
});

it('rejects a forged session cookie', function () {
    $cookies = [
        config('session.cookie') => 'attacker-controlled-cookie',
    ];

    customerMeRequest($this, $cookies, 'GET', 'customer/me')
        ->assertUnauthorized();
});

it('rejects a modified valid session cookie', function () {
    $user = customerMeAccount();
    $cookies = [];

    customerMeLogin($this, $cookies, $user);

    $cookieName = config('session.cookie');

    expect($cookies)->toHaveKey($cookieName);

    $cookies[$cookieName] .= '-tampered';

    customerMeRequest($this, $cookies, 'GET', 'customer/me')
        ->assertUnauthorized();
});

it('rejects staff and admin sessions', function (string $role) {
    $user = User::factory()->create([
        'email' => 'staff-me@example.test',
        'password' => Hash::make('CustomerPassword@123'),
        'role' => $role,
        'is_active' => true,
    ]);

    $cookies = [];

    customerMeLogin($this, $cookies, $user, 'staff');

    customerMeRequest($this, $cookies, 'GET', 'customer/me')
        ->assertUnauthorized();
})->with(['staff', 'admin']);

it('rejects a session whose database record has been revoked', function () {
    $user = customerMeAccount();
    $cookies = [];

    customerMeLogin($this, $cookies, $user);

    $sessionId = session()->getId();

    $this->assertDatabaseHas('sessions', ['id' => $sessionId]);

    DB::table('sessions')->where('id', $sessionId)->delete();

    customerMeRequest($this, $cookies, 'GET', 'customer/me')
        ->assertUnauthorized();
});

it('rejects a customer disabled after login', function () {
    $user = customerMeAccount();
    $cookies = [];

    customerMeLogin($this, $cookies, $user);

    $user->is_active = false;
    $user->save();

    customerMeRequest($this, $cookies, 'GET', 'customer/me')
        ->assertUnauthorized();
});

it('rejects a customer whose role changes after login', function () {
    $user = customerMeAccount();
    $cookies = [];

    customerMeLogin($this, $cookies, $user);

    $user->role = 'staff';
    $user->save();

    customerMeRequest($this, $cookies, 'GET', 'customer/me')
        ->assertUnauthorized();
});

it('rejects the old session after account credentials change', function (
    string $field,
    string $value
) {
    $user = customerMeAccount();
    $cookies = [];

    customerMeLogin($this, $cookies, $user);

    $user->{$field} = $value;
    $user->save();

    customerMeRequest($this, $cookies, 'GET', 'customer/me')
        ->assertUnauthorized();
})->with([
    'password changed' => ['password', 'NewCustomerPassword@456'],
    'email changed' => ['email', 'changed-customer@example.test'],
]);

it('accepts a session before the idle timeout', function () {
    $user = customerMeAccount();
    $cookies = [];

    customerMeLogin($this, $cookies, $user);

    $this->travel(119)->minutes();

    customerMeRequest($this, $cookies, 'GET', 'customer/me')
        ->assertOk();
});

it('rejects a session after the 120 minute idle timeout', function () {
    $user = customerMeAccount();
    $cookies = [];

    customerMeLogin($this, $cookies, $user);

    $this->travel(121)->minutes();

    customerMeRequest($this, $cookies, 'GET', 'customer/me')
        ->assertUnauthorized();
});

it('refreshes the idle timeout after authenticated activity', function () {
    $user = customerMeAccount();
    $cookies = [];

    customerMeLogin($this, $cookies, $user);

    $this->travel(100)->minutes();

    customerMeRequest($this, $cookies, 'GET', 'customer/me')
        ->assertOk();

    // 200 minutes since login, but only 100 since the last activity.
    $this->travel(100)->minutes();

    customerMeRequest($this, $cookies, 'GET', 'customer/me')
        ->assertOk();
});

it('enforces the absolute lifetime despite ongoing activity', function (
    int $finalMinutes,
    int $expectedStatus
) {
    $user = customerMeAccount();
    $cookies = [];

    customerMeLogin($this, $cookies, $user);

    // Keep the session active through minute 700.
    for ($i = 0; $i < 7; $i++) {
        $this->travel(100)->minutes();

        customerMeRequest($this, $cookies, 'GET', 'customer/me')
            ->assertOk();
    }

    $this->travel($finalMinutes)->minutes();

    customerMeRequest($this, $cookies, 'GET', 'customer/me')
        ->assertStatus($expectedStatus);
})->with([
    '719 minutes since login' => [19, 200],
    '721 minutes since login' => [21, 401],
]);

it('keeps separate customer sessions associated with their own accounts', function () {
    $first = customerMeAccount([
        'email' => 'first-customer@example.test',
    ]);

    $second = customerMeAccount([
        'email' => 'second-customer@example.test',
    ]);

    $firstCookies = [];
    $secondCookies = [];

    customerMeLogin($this, $firstCookies, $first);
    customerMeLogin($this, $secondCookies, $second);

    customerMeRequest($this, $firstCookies, 'GET', 'customer/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $first->email);

    customerMeRequest($this, $secondCookies, 'GET', 'customer/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $second->email);
});

it('rejects unexpected query inputs including account selectors', function (
    string $field,
    mixed $value
) {
    $user = customerMeAccount();
    $cookies = [];

    customerMeLogin($this, $cookies, $user);

    $query = http_build_query([$field => $value]);

    customerMeRequest(
        $this,
        $cookies,
        'GET',
        'customer/me?'.$query,
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    // Rejected query inputs must not change the current account.
    customerMeRequest($this, $cookies, 'GET', 'customer/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email);
})->with([
    'user id' => ['user_id', 999999],
    'primary key' => ['id', 999999],
    'other email' => ['email', 'another@example.test'],
    'role injection' => ['role', 'admin'],
    'permissions injection' => ['permissions', ['*']],
    'active flag' => ['is_active', 1],
    'arbitrary field' => ['unexpected', 'value'],
]);

it('does not expose sensitive authentication fields', function () {
    $user = customerMeAccount();
    $cookies = [];

    customerMeLogin($this, $cookies, $user);

    $response = customerMeRequest(
        $this, $cookies, 'GET', 'customer/me'
    );

    $response->assertOk();

    $forbiddenKeys = [
        'password',
        'password_hash',
        'remember_token',
        'token_hash',
        'reset_token',
        'verification_token',
        'session_id',
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

it('prevents caching of private account responses', function () {
    $user = customerMeAccount();
    $cookies = [];

    customerMeLogin($this, $cookies, $user);

    $response = customerMeRequest(
        $this, $cookies, 'GET', 'customer/me'
    );

    $response->assertOk();

    $cacheControl = strtolower(
        $response->headers->get('Cache-Control', '')
    );

    expect($cacheControl)->toContain('no-store');
    expect($cacheControl)->toContain('private');
});
