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

function customerLogoutAllAccount(array $overrides = []): User
{
    $user = User::factory()->create(array_replace([
        'email' => 'customer-logout-all@example.test',
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
 * Reload session/authentication state for every request.
 * Replay raw encrypted cookies returned by the server.
 */
function customerLogoutAllRequest(
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

function customerLogoutAllLogin(
    TestCase $test,
    array &$cookies,
    User $user,
    string $type = 'customer'
): void {
    customerLogoutAllRequest(
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

it('logs out a customer with a single session', function () {
    $user = customerLogoutAllAccount();
    $cookies = [];

    customerLogoutAllLogin($this, $cookies, $user);

    customerLogoutAllRequest(
        $this, $cookies, 'POST', 'customer/logout-all'
    )
        ->assertOk()
        ->assertJsonStructure(['message', 'data']);

    customerLogoutAllRequest($this, $cookies, 'GET', 'customer/me')
        ->assertUnauthorized();
});

it('ends every session belonging to the customer', function () {
    $user = customerLogoutAllAccount();

    $firstDevice = [];
    $secondDevice = [];
    $thirdDevice = [];

    customerLogoutAllLogin($this, $firstDevice, $user);
    $firstSessionId = session()->getId();

    customerLogoutAllLogin($this, $secondDevice, $user);
    $secondSessionId = session()->getId();

    customerLogoutAllLogin($this, $thirdDevice, $user);
    $thirdSessionId = session()->getId();

    expect(array_unique([
        $firstSessionId,
        $secondSessionId,
        $thirdSessionId,
    ]))->toHaveCount(3);

    // Initiate logout-all from the middle session.
    customerLogoutAllRequest(
        $this, $secondDevice, 'POST', 'customer/logout-all'
    )->assertOk();

    foreach ([$firstSessionId, $secondSessionId, $thirdSessionId] as $id) {
        $this->assertDatabaseMissing('sessions', ['id' => $id]);
    }

    customerLogoutAllRequest($this, $firstDevice, 'GET', 'customer/me')
        ->assertUnauthorized();

    customerLogoutAllRequest($this, $secondDevice, 'GET', 'customer/me')
        ->assertUnauthorized();

    customerLogoutAllRequest($this, $thirdDevice, 'GET', 'customer/me')
        ->assertUnauthorized();
});

it('rejects replay of cookies from all revoked sessions', function () {
    $user = customerLogoutAllAccount();

    $firstDevice = [];
    $secondDevice = [];

    customerLogoutAllLogin($this, $firstDevice, $user);
    customerLogoutAllLogin($this, $secondDevice, $user);

    $oldFirstCookies = $firstDevice;
    $oldSecondCookies = $secondDevice;

    customerLogoutAllRequest(
        $this, $firstDevice, 'POST', 'customer/logout-all'
    )->assertOk();

    customerLogoutAllRequest(
        $this, $oldFirstCookies, 'GET', 'customer/me'
    )->assertUnauthorized();

    customerLogoutAllRequest(
        $this, $oldSecondCookies, 'GET', 'customer/me'
    )->assertUnauthorized();

    customerLogoutAllRequest(
        $this, $oldSecondCookies, 'POST', 'customer/logout-all'
    )->assertUnauthorized();
});

it('rotates the initiating sessions id and csrf token', function () {
    $user = customerLogoutAllAccount();
    $cookies = [];

    customerLogoutAllLogin($this, $cookies, $user);

    $oldSessionId = session()->getId();
    $oldToken = session()->token();

    customerLogoutAllRequest(
        $this, $cookies, 'POST', 'customer/logout-all'
    )->assertOk();

    expect(session()->getId())->not->toBe($oldSessionId);
    expect(session()->token())->not->toBe($oldToken);
    expect(session()->token())->toBeString()->not->toBe('');

    $this->assertDatabaseMissing('sessions', ['id' => $oldSessionId]);
});

it('preserves every session belonging to another customer', function () {
    $user = customerLogoutAllAccount();

    $other = customerLogoutAllAccount([
        'email' => 'other-customer@example.test',
    ]);

    $cookies = [];
    $otherFirstDevice = [];
    $otherSecondDevice = [];

    customerLogoutAllLogin($this, $cookies, $user);
    customerLogoutAllLogin($this, $otherFirstDevice, $other);
    customerLogoutAllLogin($this, $otherSecondDevice, $other);

    customerLogoutAllRequest(
        $this, $cookies, 'POST', 'customer/logout-all'
    )->assertOk();

    customerLogoutAllRequest(
        $this, $otherFirstDevice, 'GET', 'customer/me'
    )
        ->assertOk()
        ->assertJsonPath('data.user.email', $other->email);

    customerLogoutAllRequest(
        $this, $otherSecondDevice, 'GET', 'customer/me'
    )
        ->assertOk()
        ->assertJsonPath('data.user.email', $other->email);
});

it('preserves unrelated staff and admin sessions', function (string $role) {
    $customer = customerLogoutAllAccount();

    $staff = User::factory()->create([
        'email' => 'unrelated-staff@example.test',
        'password' => Hash::make('CustomerPassword@123'),
        'role' => $role,
        'is_active' => true,
    ]);

    $customerCookies = [];
    $staffCookies = [];

    customerLogoutAllLogin($this, $customerCookies, $customer);
    customerLogoutAllLogin($this, $staffCookies, $staff, 'staff');

    customerLogoutAllRequest(
        $this, $customerCookies, 'POST', 'customer/logout-all'
    )->assertOk();

    customerLogoutAllRequest(
        $this, $staffCookies, 'GET', 'staff/me'
    )
        ->assertOk()
        ->assertJsonPath('data.user.email', $staff->email);
})->with(['staff', 'admin']);

it('rejects unauthenticated requests', function () {
    $cookies = [];

    customerLogoutAllRequest(
        $this, $cookies, 'POST', 'customer/logout-all'
    )->assertUnauthorized();
});

it('rejects a forged session cookie', function () {
    $cookies = [
        config('session.cookie') => 'attacker-controlled-cookie',
    ];

    customerLogoutAllRequest(
        $this, $cookies, 'POST', 'customer/logout-all'
    )->assertUnauthorized();
});

it('rejects an already revoked session without revoking another valid session', function () {
    $user = customerLogoutAllAccount();

    $revokedDevice = [];
    $validDevice = [];

    customerLogoutAllLogin($this, $revokedDevice, $user);
    $revokedSessionId = session()->getId();

    customerLogoutAllLogin($this, $validDevice, $user);

    DB::table('sessions')->where('id', $revokedSessionId)->delete();

    customerLogoutAllRequest(
        $this, $revokedDevice, 'POST', 'customer/logout-all'
    )->assertUnauthorized();

    customerLogoutAllRequest(
        $this, $validDevice, 'GET', 'customer/me'
    )->assertOk();
});

it('rejects an idle expired session', function () {
    $user = customerLogoutAllAccount();
    $cookies = [];

    customerLogoutAllLogin($this, $cookies, $user);

    $this->travel(121)->minutes();

    customerLogoutAllRequest(
        $this, $cookies, 'POST', 'customer/logout-all'
    )->assertUnauthorized();
});

it('rejects staff and admin sessions without revoking them', function (
    string $role
) {
    $user = User::factory()->create([
        'email' => 'wrong-type@example.test',
        'password' => Hash::make('CustomerPassword@123'),
        'role' => $role,
        'is_active' => true,
    ]);

    $cookies = [];

    customerLogoutAllLogin($this, $cookies, $user, 'staff');

    customerLogoutAllRequest(
        $this, $cookies, 'POST', 'customer/logout-all'
    )->assertUnauthorized();

    customerLogoutAllRequest($this, $cookies, 'GET', 'staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email);
})->with(['staff', 'admin']);

it('rejects unexpected fields without revoking either customer session', function (
    string $field,
    mixed $value
) {
    $user = customerLogoutAllAccount();

    $firstDevice = [];
    $secondDevice = [];

    customerLogoutAllLogin($this, $firstDevice, $user);
    customerLogoutAllLogin($this, $secondDevice, $user);

    customerLogoutAllRequest(
        $this,
        $firstDevice,
        'POST',
        'customer/logout-all',
        [$field => $value],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    customerLogoutAllRequest(
        $this, $firstDevice, 'GET', 'customer/me'
    )->assertOk();

    customerLogoutAllRequest(
        $this, $secondDevice, 'GET', 'customer/me'
    )->assertOk();
})->with([
    'another user id' => ['user_id', 999999],
    'role' => ['role', 'admin'],
    'permissions' => ['permissions', ['*']],
    'active flag' => ['is_active', true],
    'session selector' => ['session_id', 'another-session'],
    'arbitrary field' => ['unexpected', 'value'],
]);

it('preserves credentials and account records', function () {
    $user = customerLogoutAllAccount();

    $originalHash = $user->password;
    $usersBefore = DB::table('users')->count();
    $customersBefore = DB::table('customers')->count();

    $cookies = [];

    customerLogoutAllLogin($this, $cookies, $user);

    customerLogoutAllRequest(
        $this, $cookies, 'POST', 'customer/logout-all'
    )->assertOk();

    $user->refresh();

    expect($user->password)->toBe($originalHash);
    expect($user->role)->toBe('customer');
    expect($user->is_active)->toBeTrue();

    expect(DB::table('users')->count())->toBe($usersBefore);
    expect(DB::table('customers')->count())->toBe($customersBefore);
});

it('allows a fresh login without restoring older sessions', function () {
    $user = customerLogoutAllAccount();

    $firstDevice = [];
    $secondDevice = [];

    customerLogoutAllLogin($this, $firstDevice, $user);
    customerLogoutAllLogin($this, $secondDevice, $user);

    $oldSecondCookies = $secondDevice;

    customerLogoutAllRequest(
        $this, $firstDevice, 'POST', 'customer/logout-all'
    )->assertOk();

    customerLogoutAllLogin($this, $firstDevice, $user);

    customerLogoutAllRequest(
        $this, $firstDevice, 'GET', 'customer/me'
    )
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email);

    customerLogoutAllRequest(
        $this, $oldSecondCookies, 'GET', 'customer/me'
    )->assertUnauthorized();
});
