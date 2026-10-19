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
        'session.driver' => 'database',
        'session.lottery' => [0, 100],
    ]);
});

afterEach(function () {
    $this->travelBack();
});

function staffMeAccount(array $overrides = []): User
{
    return User::factory()->create(array_replace([
        'email' => 'staff-me@example.test',
        'password' => Hash::make('StaffPassword@123'),
        'role' => 'staff',
        'is_active' => true,
    ], $overrides));
}

/**
 * Reload authentication and sessions for each simulated HTTP request.
 * Replay the raw encrypted session cookie returned by the server.
 */
function staffMeRequest(
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

function staffMeLogin(
    TestCase $test,
    array &$cookies,
    User $user,
    string $type = 'staff'
): void {
    staffMeRequest(
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

it('returns the authenticated staff or admin account', function (string $role) {
    $user = staffMeAccount(['role' => $role]);
    $cookies = [];

    staffMeLogin($this, $cookies, $user);

    $response = staffMeRequest($this, $cookies, 'GET', 'staff/me');

    $response
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.user.role', $role);

    expect((string) $response->json('data.user.id'))
        ->toBe((string) $user->id);
})->with(['staff', 'admin']);

it('allows active unverified staff and admin accounts', function (string $role) {
    $user = staffMeAccount([
        'role' => $role,
        'email_verified_at' => null,
    ]);

    $cookies = [];

    staffMeLogin($this, $cookies, $user);

    staffMeRequest($this, $cookies, 'GET', 'staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email);
})->with(['staff', 'admin']);

it('rejects a request without a session', function () {
    $cookies = [];

    staffMeRequest($this, $cookies, 'GET', 'staff/me')
        ->assertUnauthorized()
        ->assertJsonStructure(['message', 'data']);
});

it('does not authenticate using a bearer token alone', function () {
    $this->withHeaders([
        'Authorization' => 'Bearer attacker-controlled-token',
    ])
        ->getJson('/api/auth/staff/me')
        ->assertUnauthorized();
});

it('rejects a forged session cookie', function () {
    $cookies = [
        config('session.cookie') => 'attacker-controlled-cookie',
    ];

    staffMeRequest($this, $cookies, 'GET', 'staff/me')
        ->assertUnauthorized();
});

it('rejects a tampered valid session cookie', function () {
    $user = staffMeAccount();
    $cookies = [];

    staffMeLogin($this, $cookies, $user);

    $cookieName = config('session.cookie');

    expect($cookies)->toHaveKey($cookieName);

    $cookies[$cookieName] .= '-tampered';

    staffMeRequest($this, $cookies, 'GET', 'staff/me')
        ->assertUnauthorized();
});

it('rejects a customer session', function () {
    $user = staffMeAccount(['role' => 'customer']);

    DB::table('customers')->insert([
        'user_id' => $user->id,
    ]);

    $cookies = [];

    staffMeLogin($this, $cookies, $user, 'customer');

    staffMeRequest($this, $cookies, 'GET', 'staff/me')
        ->assertUnauthorized();
});

it('rejects a revoked session', function (string $role) {
    $user = staffMeAccount(['role' => $role]);
    $cookies = [];

    staffMeLogin($this, $cookies, $user);

    $sessionId = session()->getId();

    $this->assertDatabaseHas('sessions', ['id' => $sessionId]);

    DB::table('sessions')->where('id', $sessionId)->delete();

    staffMeRequest($this, $cookies, 'GET', 'staff/me')
        ->assertUnauthorized();
})->with(['staff', 'admin']);

it('rejects an account disabled after login', function (string $role) {
    $user = staffMeAccount(['role' => $role]);
    $cookies = [];

    staffMeLogin($this, $cookies, $user);

    $user->is_active = false;
    $user->save();

    staffMeRequest($this, $cookies, 'GET', 'staff/me')
        ->assertUnauthorized();
})->with(['staff', 'admin']);

it('rejects the existing session after an account role changes', function (
    string $originalRole,
    string $newRole
) {
    $user = staffMeAccount(['role' => $originalRole]);
    $cookies = [];

    staffMeLogin($this, $cookies, $user);

    $user->role = $newRole;
    $user->save();

    staffMeRequest($this, $cookies, 'GET', 'staff/me')
        ->assertUnauthorized();
})->with([
    'staff becomes customer' => ['staff', 'customer'],
    'admin becomes customer' => ['admin', 'customer'],
    'admin is demoted to staff' => ['admin', 'staff'],
    'staff is promoted to admin' => ['staff', 'admin'],
]);

it('rejects the old session after account credentials change', function (
    string $role,
    string $field,
    string $value
) {
    $user = staffMeAccount(['role' => $role]);
    $cookies = [];

    staffMeLogin($this, $cookies, $user);

    $user->{$field} = $value;
    $user->save();

    staffMeRequest($this, $cookies, 'GET', 'staff/me')
        ->assertUnauthorized();
})->with(['staff', 'admin'])->with([
    'password changed' => ['password', 'NewStaffPassword@456'],
    'email changed' => ['email', 'changed-staff@example.test'],
]);

it('accepts a session before the idle timeout', function (string $role) {
    $user = staffMeAccount(['role' => $role]);
    $cookies = [];

    staffMeLogin($this, $cookies, $user);

    $this->travel(119)->minutes();

    staffMeRequest($this, $cookies, 'GET', 'staff/me')
        ->assertOk();
})->with(['staff', 'admin']);

it('rejects a session after 120 idle minutes', function (string $role) {
    $user = staffMeAccount(['role' => $role]);
    $cookies = [];

    staffMeLogin($this, $cookies, $user);

    $this->travel(121)->minutes();

    staffMeRequest($this, $cookies, 'GET', 'staff/me')
        ->assertUnauthorized();
})->with(['staff', 'admin']);

it('refreshes the idle timeout after authenticated activity', function (
    string $role
) {
    $user = staffMeAccount(['role' => $role]);
    $cookies = [];

    staffMeLogin($this, $cookies, $user);

    $this->travel(100)->minutes();

    staffMeRequest($this, $cookies, 'GET', 'staff/me')
        ->assertOk();

    $this->travel(100)->minutes();

    staffMeRequest($this, $cookies, 'GET', 'staff/me')
        ->assertOk();
})->with(['staff', 'admin']);

it('enforces the absolute lifetime despite ongoing activity', function (
    string $role,
    int $finalMinutes,
    int $expectedStatus
) {
    $user = staffMeAccount(['role' => $role]);
    $cookies = [];

    staffMeLogin($this, $cookies, $user);

    for ($i = 0; $i < 7; $i++) {
        $this->travel(100)->minutes();

        staffMeRequest($this, $cookies, 'GET', 'staff/me')
            ->assertOk();
    }

    $this->travel($finalMinutes)->minutes();

    staffMeRequest($this, $cookies, 'GET', 'staff/me')
        ->assertStatus($expectedStatus);
})->with(['staff', 'admin'])->with([
    '719 minutes since login' => [19, 200],
    '721 minutes since login' => [21, 401],
]);

it('keeps staff and admin sessions associated with their own accounts', function () {
    $staff = staffMeAccount([
        'email' => 'separate-staff@example.test',
        'role' => 'staff',
    ]);

    $admin = staffMeAccount([
        'email' => 'separate-admin@example.test',
        'role' => 'admin',
    ]);

    $staffCookies = [];
    $adminCookies = [];

    staffMeLogin($this, $staffCookies, $staff);
    staffMeLogin($this, $adminCookies, $admin);

    staffMeRequest($this, $staffCookies, 'GET', 'staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $staff->email)
        ->assertJsonPath('data.user.role', 'staff');

    staffMeRequest($this, $adminCookies, 'GET', 'staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $admin->email)
        ->assertJsonPath('data.user.role', 'admin');
});

it('rejects unexpected query parameters and account selectors', function (
    string $field,
    mixed $value
) {
    $user = staffMeAccount();
    $cookies = [];

    staffMeLogin($this, $cookies, $user);

    $query = http_build_query([$field => $value]);

    staffMeRequest(
        $this,
        $cookies,
        'GET',
        'staff/me?'.$query,
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    staffMeRequest($this, $cookies, 'GET', 'staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.user.role', 'staff');
})->with([
    'user id' => ['user_id', 999999],
    'primary key' => ['id', 999999],
    'other email' => ['email', 'another@example.test'],
    'role injection' => ['role', 'admin'],
    'permissions injection' => ['permissions', ['*']],
    'active flag' => ['is_active', 1],
    'arbitrary field' => ['unexpected', 'value'],
]);

it('does not expose sensitive authentication fields', function (string $role) {
    $user = staffMeAccount(['role' => $role]);
    $cookies = [];

    staffMeLogin($this, $cookies, $user);

    $response = staffMeRequest($this, $cookies, 'GET', 'staff/me');

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
})->with(['staff', 'admin']);

it('prevents caching of private account responses', function (string $role) {
    $user = staffMeAccount(['role' => $role]);
    $cookies = [];

    staffMeLogin($this, $cookies, $user);

    $response = staffMeRequest($this, $cookies, 'GET', 'staff/me');

    $response->assertOk();

    $cacheControl = strtolower(
        $response->headers->get('Cache-Control', '')
    );

    expect($cacheControl)->toContain('no-store');
    expect($cacheControl)->toContain('private');
})->with(['staff', 'admin']);
