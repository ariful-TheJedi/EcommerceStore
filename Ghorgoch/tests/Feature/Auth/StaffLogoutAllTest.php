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

function staffLogoutAllAccount(array $overrides = []): User
{
    return User::factory()->create(array_replace([
        'email' => 'staff-logout-all@example.test',
        'password' => Hash::make('StaffPassword@123'),
        'role' => 'staff',
        'is_active' => true,
    ], $overrides));
}

/**
 * Reload session/authentication state for each simulated HTTP request.
 * CSRF enforcement is covered separately.
 */
function staffLogoutAllRequest(
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

function staffLogoutAllLogin(
    TestCase $test,
    array &$cookies,
    User $user,
    string $type = 'staff'
): void {
    staffLogoutAllRequest(
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

it('logs out an account with one session', function (string $role) {
    $user = staffLogoutAllAccount(['role' => $role]);
    $cookies = [];

    staffLogoutAllLogin($this, $cookies, $user);

    staffLogoutAllRequest(
        $this, $cookies, 'POST', 'staff/logout-all'
    )
        ->assertOk()
        ->assertJsonStructure(['message', 'data']);

    staffLogoutAllRequest($this, $cookies, 'GET', 'staff/me')
        ->assertUnauthorized();
})->with(['staff', 'admin']);

it('ends every session belonging to the staff or admin account', function (
    string $role
) {
    $user = staffLogoutAllAccount(['role' => $role]);

    $firstDevice = [];
    $secondDevice = [];
    $thirdDevice = [];

    staffLogoutAllLogin($this, $firstDevice, $user);
    $firstId = session()->getId();

    staffLogoutAllLogin($this, $secondDevice, $user);
    $secondId = session()->getId();

    staffLogoutAllLogin($this, $thirdDevice, $user);
    $thirdId = session()->getId();

    expect(array_unique([$firstId, $secondId, $thirdId]))
        ->toHaveCount(3);

    staffLogoutAllRequest(
        $this, $secondDevice, 'POST', 'staff/logout-all'
    )->assertOk();

    foreach ([$firstId, $secondId, $thirdId] as $id) {
        $this->assertDatabaseMissing('sessions', ['id' => $id]);
    }

    staffLogoutAllRequest($this, $firstDevice, 'GET', 'staff/me')
        ->assertUnauthorized();

    staffLogoutAllRequest($this, $secondDevice, 'GET', 'staff/me')
        ->assertUnauthorized();

    staffLogoutAllRequest($this, $thirdDevice, 'GET', 'staff/me')
        ->assertUnauthorized();
})->with(['staff', 'admin']);

it('rejects replay of cookies from revoked sessions', function (string $role) {
    $user = staffLogoutAllAccount(['role' => $role]);

    $firstDevice = [];
    $secondDevice = [];

    staffLogoutAllLogin($this, $firstDevice, $user);
    staffLogoutAllLogin($this, $secondDevice, $user);

    $oldFirstCookies = $firstDevice;
    $oldSecondCookies = $secondDevice;

    staffLogoutAllRequest(
        $this, $firstDevice, 'POST', 'staff/logout-all'
    )->assertOk();

    staffLogoutAllRequest(
        $this, $oldFirstCookies, 'GET', 'staff/me'
    )->assertUnauthorized();

    staffLogoutAllRequest(
        $this, $oldSecondCookies, 'GET', 'staff/me'
    )->assertUnauthorized();

    staffLogoutAllRequest(
        $this, $oldSecondCookies, 'POST', 'staff/logout-all'
    )->assertUnauthorized();
})->with(['staff', 'admin']);

it('rotates the initiating sessions id and csrf token', function (string $role) {
    $user = staffLogoutAllAccount(['role' => $role]);
    $cookies = [];

    staffLogoutAllLogin($this, $cookies, $user);

    $oldId = session()->getId();
    $oldToken = session()->token();

    staffLogoutAllRequest(
        $this, $cookies, 'POST', 'staff/logout-all'
    )->assertOk();

    expect(session()->getId())->not->toBe($oldId);
    expect(session()->token())->not->toBe($oldToken);
    expect(session()->token())->toBeString()->not->toBe('');

    $this->assertDatabaseMissing('sessions', ['id' => $oldId]);
})->with(['staff', 'admin']);

it('preserves all sessions of a different staff or admin account', function (
    string $role,
    string $otherRole
) {
    $user = staffLogoutAllAccount(['role' => $role]);

    $other = staffLogoutAllAccount([
        'email' => 'other-staff@example.test',
        'role' => $otherRole,
    ]);

    $cookies = [];
    $otherFirstDevice = [];
    $otherSecondDevice = [];

    staffLogoutAllLogin($this, $cookies, $user);
    staffLogoutAllLogin($this, $otherFirstDevice, $other);
    staffLogoutAllLogin($this, $otherSecondDevice, $other);

    staffLogoutAllRequest(
        $this, $cookies, 'POST', 'staff/logout-all'
    )->assertOk();

    staffLogoutAllRequest(
        $this, $otherFirstDevice, 'GET', 'staff/me'
    )
        ->assertOk()
        ->assertJsonPath('data.user.email', $other->email)
        ->assertJsonPath('data.user.role', $otherRole);

    staffLogoutAllRequest(
        $this, $otherSecondDevice, 'GET', 'staff/me'
    )
        ->assertOk()
        ->assertJsonPath('data.user.email', $other->email)
        ->assertJsonPath('data.user.role', $otherRole);
})->with([
    'staff and staff' => ['staff', 'staff'],
    'staff and admin' => ['staff', 'admin'],
    'admin and staff' => ['admin', 'staff'],
    'admin and admin' => ['admin', 'admin'],
]);

it('preserves an unrelated customers session', function (string $role) {
    $user = staffLogoutAllAccount(['role' => $role]);

    $customer = staffLogoutAllAccount([
        'email' => 'unrelated-customer@example.test',
        'role' => 'customer',
    ]);

    DB::table('customers')->insert([
        'user_id' => $customer->id,
    ]);

    $staffCookies = [];
    $customerCookies = [];

    staffLogoutAllLogin($this, $staffCookies, $user);
    staffLogoutAllLogin($this, $customerCookies, $customer, 'customer');

    staffLogoutAllRequest(
        $this, $staffCookies, 'POST', 'staff/logout-all'
    )->assertOk();

    staffLogoutAllRequest(
        $this, $customerCookies, 'GET', 'customer/me'
    )
        ->assertOk()
        ->assertJsonPath('data.user.email', $customer->email);
})->with(['staff', 'admin']);

it('rejects unauthenticated requests', function () {
    $cookies = [];

    staffLogoutAllRequest(
        $this, $cookies, 'POST', 'staff/logout-all'
    )->assertUnauthorized();
});

it('rejects a forged session cookie', function () {
    $cookies = [
        config('session.cookie') => 'attacker-controlled-cookie',
    ];

    staffLogoutAllRequest(
        $this, $cookies, 'POST', 'staff/logout-all'
    )->assertUnauthorized();
});

it('rejects a revoked session without revoking another valid session', function () {
    $user = staffLogoutAllAccount();

    $revokedDevice = [];
    $validDevice = [];

    staffLogoutAllLogin($this, $revokedDevice, $user);
    $revokedId = session()->getId();

    staffLogoutAllLogin($this, $validDevice, $user);

    DB::table('sessions')->where('id', $revokedId)->delete();

    staffLogoutAllRequest(
        $this, $revokedDevice, 'POST', 'staff/logout-all'
    )->assertUnauthorized();

    staffLogoutAllRequest(
        $this, $validDevice, 'GET', 'staff/me'
    )->assertOk();
});

it('rejects an idle expired session', function () {
    $user = staffLogoutAllAccount();
    $cookies = [];

    staffLogoutAllLogin($this, $cookies, $user);

    $this->travel(121)->minutes();

    staffLogoutAllRequest(
        $this, $cookies, 'POST', 'staff/logout-all'
    )->assertUnauthorized();
});

it('rejects a customer session without ending any customer sessions', function () {
    $customer = staffLogoutAllAccount(['role' => 'customer']);

    DB::table('customers')->insert([
        'user_id' => $customer->id,
    ]);

    $firstDevice = [];
    $secondDevice = [];

    staffLogoutAllLogin($this, $firstDevice, $customer, 'customer');
    staffLogoutAllLogin($this, $secondDevice, $customer, 'customer');

    staffLogoutAllRequest(
        $this, $firstDevice, 'POST', 'staff/logout-all'
    )->assertUnauthorized();

    staffLogoutAllRequest(
        $this, $firstDevice, 'GET', 'customer/me'
    )->assertOk();

    staffLogoutAllRequest(
        $this, $secondDevice, 'GET', 'customer/me'
    )->assertOk();
});

it('rejects unexpected fields without revoking valid sessions', function (
    string $role,
    string $field,
    mixed $value
) {
    $user = staffLogoutAllAccount(['role' => $role]);

    $firstDevice = [];
    $secondDevice = [];

    staffLogoutAllLogin($this, $firstDevice, $user);
    staffLogoutAllLogin($this, $secondDevice, $user);

    staffLogoutAllRequest(
        $this,
        $firstDevice,
        'POST',
        'staff/logout-all',
        [$field => $value],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    staffLogoutAllRequest(
        $this, $firstDevice, 'GET', 'staff/me'
    )->assertOk();

    staffLogoutAllRequest(
        $this, $secondDevice, 'GET', 'staff/me'
    )->assertOk();
})->with(['staff', 'admin'])->with([
    'another user id' => ['user_id', 999999],
    'role' => ['role', 'admin'],
    'permissions' => ['permissions', ['*']],
    'active flag' => ['is_active', true],
    'session selector' => ['session_id', 'another-session'],
    'arbitrary field' => ['unexpected', 'value'],
]);

it('preserves credentials and account records', function (string $role) {
    $user = staffLogoutAllAccount(['role' => $role]);

    $originalHash = $user->password;
    $usersBefore = DB::table('users')->count();
    $customersBefore = DB::table('customers')->count();

    $cookies = [];

    staffLogoutAllLogin($this, $cookies, $user);

    staffLogoutAllRequest(
        $this, $cookies, 'POST', 'staff/logout-all'
    )->assertOk();

    $user->refresh();

    expect($user->password)->toBe($originalHash);
    expect($user->role)->toBe($role);
    expect($user->is_active)->toBeTrue();

    expect(DB::table('users')->count())->toBe($usersBefore);
    expect(DB::table('customers')->count())->toBe($customersBefore);
})->with(['staff', 'admin']);

it('allows a fresh login without restoring revoked sessions', function (string $role) {
    $user = staffLogoutAllAccount(['role' => $role]);

    $firstDevice = [];
    $secondDevice = [];

    staffLogoutAllLogin($this, $firstDevice, $user);
    staffLogoutAllLogin($this, $secondDevice, $user);

    $oldSecondCookies = $secondDevice;

    staffLogoutAllRequest(
        $this, $firstDevice, 'POST', 'staff/logout-all'
    )->assertOk();

    staffLogoutAllLogin($this, $firstDevice, $user);

    staffLogoutAllRequest(
        $this, $firstDevice, 'GET', 'staff/me'
    )
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email);

    staffLogoutAllRequest(
        $this, $oldSecondCookies, 'GET', 'staff/me'
    )->assertUnauthorized();
})->with(['staff', 'admin']);
