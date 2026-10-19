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

function staffLogoutAccount(array $overrides = []): User
{
    return User::factory()->create(array_replace([
        'email' => 'staff-logout@example.test',
        'password' => Hash::make('StaffPassword@123'),
        'role' => 'staff',
        'is_active' => true,
    ], $overrides));
}

/**
 * Reload session/authentication state for each request.
 * CSRF enforcement is covered separately.
 */
function staffLogoutRequest(
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

function staffLogoutLogin(
    TestCase $test,
    array &$cookies,
    User $user,
    string $type = 'staff'
): void {
    staffLogoutRequest(
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

it('logs out the current staff or admin session', function (string $role) {
    $user = staffLogoutAccount(['role' => $role]);
    $cookies = [];

    staffLogoutLogin($this, $cookies, $user);

    staffLogoutRequest($this, $cookies, 'POST', 'staff/logout')
        ->assertOk()
        ->assertJsonStructure(['message', 'data']);

    staffLogoutRequest($this, $cookies, 'GET', 'staff/me')
        ->assertUnauthorized();
})->with(['staff', 'admin']);

it('removes the old session and rotates the session id and csrf token', function (
    string $role
) {
    $user = staffLogoutAccount(['role' => $role]);
    $cookies = [];

    staffLogoutLogin($this, $cookies, $user);

    $oldSessionId = session()->getId();
    $oldToken = session()->token();

    $this->assertDatabaseHas('sessions', ['id' => $oldSessionId]);

    staffLogoutRequest($this, $cookies, 'POST', 'staff/logout')
        ->assertOk();

    expect(session()->getId())->not->toBe($oldSessionId);
    expect(session()->token())->not->toBe($oldToken);
    expect(session()->token())->toBeString()->not->toBe('');

    $this->assertDatabaseMissing('sessions', ['id' => $oldSessionId]);
})->with(['staff', 'admin']);

it('rejects replay of the old authenticated cookie', function (string $role) {
    $user = staffLogoutAccount(['role' => $role]);
    $cookies = [];

    staffLogoutLogin($this, $cookies, $user);

    $oldCookies = $cookies;

    staffLogoutRequest($this, $cookies, 'POST', 'staff/logout')
        ->assertOk();

    staffLogoutRequest($this, $oldCookies, 'GET', 'staff/me')
        ->assertUnauthorized();

    staffLogoutRequest($this, $oldCookies, 'POST', 'staff/logout')
        ->assertUnauthorized();
})->with(['staff', 'admin']);

it('preserves another session belonging to the same account', function (
    string $role
) {
    $user = staffLogoutAccount(['role' => $role]);

    $firstDevice = [];
    $secondDevice = [];

    staffLogoutLogin($this, $firstDevice, $user);
    $firstSessionId = session()->getId();

    staffLogoutLogin($this, $secondDevice, $user);
    $secondSessionId = session()->getId();

    expect($firstSessionId)->not->toBe($secondSessionId);

    staffLogoutRequest($this, $firstDevice, 'POST', 'staff/logout')
        ->assertOk();

    $this->assertDatabaseMissing('sessions', ['id' => $firstSessionId]);
    $this->assertDatabaseHas('sessions', ['id' => $secondSessionId]);

    staffLogoutRequest($this, $firstDevice, 'GET', 'staff/me')
        ->assertUnauthorized();

    staffLogoutRequest($this, $secondDevice, 'GET', 'staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.user.role', $role);
})->with(['staff', 'admin']);

it('does not log out a different staff or admin account', function (
    string $role,
    string $otherRole
) {
    $user = staffLogoutAccount(['role' => $role]);

    $other = staffLogoutAccount([
        'email' => 'other-staff@example.test',
        'role' => $otherRole,
    ]);

    $cookies = [];
    $otherCookies = [];

    staffLogoutLogin($this, $cookies, $user);
    staffLogoutLogin($this, $otherCookies, $other);

    staffLogoutRequest($this, $cookies, 'POST', 'staff/logout')
        ->assertOk();

    staffLogoutRequest($this, $otherCookies, 'GET', 'staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $other->email)
        ->assertJsonPath('data.user.role', $otherRole);
})->with([
    'staff and staff' => ['staff', 'staff'],
    'staff and admin' => ['staff', 'admin'],
    'admin and staff' => ['admin', 'staff'],
    'admin and admin' => ['admin', 'admin'],
]);

it('rejects logout without authentication', function () {
    $cookies = [];

    staffLogoutRequest($this, $cookies, 'POST', 'staff/logout')
        ->assertUnauthorized();
});

it('rejects a forged session cookie', function () {
    $cookies = [
        config('session.cookie') => 'attacker-controlled-cookie',
    ];

    staffLogoutRequest($this, $cookies, 'POST', 'staff/logout')
        ->assertUnauthorized();
});

it('rejects a revoked session', function () {
    $user = staffLogoutAccount();
    $cookies = [];

    staffLogoutLogin($this, $cookies, $user);

    DB::table('sessions')
        ->where('id', session()->getId())
        ->delete();

    staffLogoutRequest($this, $cookies, 'POST', 'staff/logout')
        ->assertUnauthorized();
});

it('rejects an idle expired session', function () {
    $user = staffLogoutAccount();
    $cookies = [];

    staffLogoutLogin($this, $cookies, $user);

    $this->travel(121)->minutes();

    staffLogoutRequest($this, $cookies, 'POST', 'staff/logout')
        ->assertUnauthorized();
});

it('rejects a customer session without logging the customer out', function () {
    $customer = staffLogoutAccount(['role' => 'customer']);

    DB::table('customers')->insert([
        'user_id' => $customer->id,
    ]);

    $cookies = [];

    staffLogoutLogin($this, $cookies, $customer, 'customer');

    staffLogoutRequest($this, $cookies, 'POST', 'staff/logout')
        ->assertUnauthorized();

    staffLogoutRequest($this, $cookies, 'GET', 'customer/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $customer->email);
});

it('rejects unexpected inputs without ending the valid session', function (
    string $role,
    string $field,
    mixed $value
) {
    $user = staffLogoutAccount(['role' => $role]);
    $cookies = [];

    staffLogoutLogin($this, $cookies, $user);

    staffLogoutRequest(
        $this,
        $cookies,
        'POST',
        'staff/logout',
        [$field => $value],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    staffLogoutRequest($this, $cookies, 'GET', 'staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.user.role', $role);
})->with(['staff', 'admin'])->with([
    'another user id' => ['user_id', 999999],
    'role' => ['role', 'admin'],
    'permissions' => ['permissions', ['*']],
    'active flag' => ['is_active', true],
    'all devices flag' => ['all_devices', true],
    'session selector' => ['session_id', 'another-session'],
    'arbitrary field' => ['unexpected', 'value'],
]);

it('does not modify account credentials or delete account records', function (
    string $role
) {
    $user = staffLogoutAccount(['role' => $role]);

    $originalHash = $user->password;
    $usersBefore = DB::table('users')->count();
    $customersBefore = DB::table('customers')->count();

    $cookies = [];

    staffLogoutLogin($this, $cookies, $user);

    staffLogoutRequest($this, $cookies, 'POST', 'staff/logout')
        ->assertOk();

    $user->refresh();

    expect($user->password)->toBe($originalHash);
    expect($user->role)->toBe($role);
    expect($user->is_active)->toBeTrue();

    expect(DB::table('users')->count())->toBe($usersBefore);
    expect(DB::table('customers')->count())->toBe($customersBefore);
})->with(['staff', 'admin']);

it('allows a fresh login after logout', function (string $role) {
    $user = staffLogoutAccount(['role' => $role]);
    $cookies = [];

    staffLogoutLogin($this, $cookies, $user);

    staffLogoutRequest($this, $cookies, 'POST', 'staff/logout')
        ->assertOk();

    staffLogoutLogin($this, $cookies, $user);

    staffLogoutRequest($this, $cookies, 'GET', 'staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.user.role', $role);
})->with(['staff', 'admin']);
