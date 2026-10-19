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

    // Enable real CSRF checks instead of Laravel's testing bypass.
    app()->instance('env', 'auth-flow-tests');

    expect(app()->runningUnitTests())->toBeFalse();
});

afterEach(function () {
    app()->instance('env', 'testing');

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
});

function authFlowAccount(string $role, string $email): User
{
    $user = User::factory()->create([
        'email' => $email,
        'password' => Hash::make('OriginalPassword@123'),
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => null,
    ]);

    if ($role === 'customer') {
        DB::table('customers')->insert([
            'user_id' => $user->id,
        ]);
    }

    return $user;
}

/**
 * Each simulated device keeps its own raw session cookies.
 * Reload services to prevent cached authentication leaking between devices.
 */
function authFlowRequest(
    TestCase $test,
    array &$cookies,
    string $method,
    string $path,
    array $data = [],
    ?string $token = null
): TestResponse {
    Auth::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');

    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    if ($token !== null) {
        $headers['HTTP_X_CSRF_TOKEN'] = $token;
    }

    $response = $test->call(
        $method,
        '/api/auth/'.$path,
        [],
        $cookies,
        [],
        $headers,
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

function authFlowCsrf(TestCase $test, array &$cookies): string
{
    $response = authFlowRequest(
        $test,
        $cookies,
        'GET',
        'csrf-token',
    );

    $response->assertOk();

    $token = $response->json('data.csrf_token');

    expect($token)->toBeString()->not->toBe('');

    return $token;
}

function authFlowLogin(
    TestCase $test,
    array &$cookies,
    string $type,
    User $user,
    string $password = 'OriginalPassword@123'
): string {
    $token = authFlowCsrf($test, $cookies);

    $response = authFlowRequest(
        $test,
        $cookies,
        'POST',
        $type.'/login',
        [
            'email' => $user->email,
            'password' => $password,
        ],
        $token,
    );

    $response
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email);

    $newToken = $response->json('data.csrf_token');

    expect($newToken)->toBeString()->not->toBe('');
    expect($newToken)->not->toBe($token);

    return $newToken;
}

dataset('auth flow account types', [
    'customer' => ['customer', 'customer'],
    'staff' => ['staff', 'staff'],
    'admin' => ['staff', 'admin'],
]);

it('completes csrf login account access logout and fresh login', function (
    string $type,
    string $role
) {
    $user = authFlowAccount($role, 'lifecycle@example.test');
    $cookies = [];

    authFlowRequest($this, $cookies, 'GET', $type.'/me')
        ->assertUnauthorized();

    $initialToken = authFlowCsrf($this, $cookies);

    // The session cookie alone must not authorize a POST.
    authFlowRequest(
        $this,
        $cookies,
        'POST',
        $type.'/login',
        [
            'email' => $user->email,
            'password' => 'OriginalPassword@123',
        ],
    )->assertStatus(419);

    $login = authFlowRequest(
        $this,
        $cookies,
        'POST',
        $type.'/login',
        [
            'email' => $user->email,
            'password' => 'OriginalPassword@123',
        ],
        $initialToken,
    );

    $login->assertOk();

    $loginToken = $login->json('data.csrf_token');

    expect($loginToken)->toBeString()->not->toBe('');
    expect($loginToken)->not->toBe($initialToken);

    authFlowRequest($this, $cookies, 'GET', $type.'/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.user.role', $role);

    $otherType = $type === 'customer' ? 'staff' : 'customer';

    authFlowRequest($this, $cookies, 'GET', $otherType.'/me')
        ->assertUnauthorized();

    // A token issued before login must no longer work.
    authFlowRequest(
        $this,
        $cookies,
        'POST',
        $type.'/logout',
        token: $initialToken,
    )->assertStatus(419);

    $oldCookies = $cookies;

    authFlowRequest(
        $this,
        $cookies,
        'POST',
        $type.'/logout',
        token: $loginToken,
    )->assertOk();

    authFlowRequest($this, $cookies, 'GET', $type.'/me')
        ->assertUnauthorized();

    authFlowRequest($this, $oldCookies, 'GET', $type.'/me')
        ->assertUnauthorized();

    authFlowLogin($this, $cookies, $type, $user);

    authFlowRequest($this, $cookies, 'GET', $type.'/me')
        ->assertOk();
})->with('auth flow account types');

it('logs out one device while preserving the other then revokes all own devices', function (
    string $type,
    string $role
) {
    $user = authFlowAccount($role, 'multi-device@example.test');
    $other = authFlowAccount($role, 'unrelated-account@example.test');

    $firstDevice = [];
    $secondDevice = [];
    $otherDevice = [];

    $firstToken = authFlowLogin($this, $firstDevice, $type, $user);
    $secondToken = authFlowLogin($this, $secondDevice, $type, $user);

    authFlowLogin($this, $otherDevice, $type, $other);

    authFlowRequest(
        $this,
        $firstDevice,
        'POST',
        $type.'/logout',
        token: $firstToken,
    )->assertOk();

    authFlowRequest($this, $firstDevice, 'GET', $type.'/me')
        ->assertUnauthorized();

    authFlowRequest($this, $secondDevice, 'GET', $type.'/me')
        ->assertOk();

    // Create another own session before testing logout-all.
    authFlowLogin($this, $firstDevice, $type, $user);

    $oldFirstCookies = $firstDevice;
    $oldSecondCookies = $secondDevice;

    authFlowRequest(
        $this,
        $secondDevice,
        'POST',
        $type.'/logout-all',
        token: $secondToken,
    )->assertOk();

    authFlowRequest($this, $oldFirstCookies, 'GET', $type.'/me')
        ->assertUnauthorized();

    authFlowRequest($this, $oldSecondCookies, 'GET', $type.'/me')
        ->assertUnauthorized();

    authFlowRequest($this, $otherDevice, 'GET', $type.'/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $other->email);
})->with('auth flow account types');

it('changes a password revokes all own sessions and requires the new credentials', function (
    string $type,
    string $role
) {
    $user = authFlowAccount($role, 'password-flow@example.test');
    $other = authFlowAccount($role, 'other-password-flow@example.test');

    $firstDevice = [];
    $secondDevice = [];
    $otherDevice = [];

    $token = authFlowLogin($this, $firstDevice, $type, $user);

    authFlowLogin($this, $secondDevice, $type, $user);
    authFlowLogin($this, $otherDevice, $type, $other);

    $oldFirstCookies = $firstDevice;
    $oldSecondCookies = $secondDevice;

    authFlowRequest(
        $this,
        $firstDevice,
        'POST',
        $type.'/password',
        [
            'current_password' => 'OriginalPassword@123',
            'password' => 'ReplacementPassword@456',
            'password_confirmation' => 'ReplacementPassword@456',
        ],
        $token,
    )->assertOk();

    expect(Hash::check(
        'ReplacementPassword@456',
        $user->fresh()->password,
    ))->toBeTrue();

    authFlowRequest($this, $oldFirstCookies, 'GET', $type.'/me')
        ->assertUnauthorized();

    authFlowRequest($this, $oldSecondCookies, 'GET', $type.'/me')
        ->assertUnauthorized();

    authFlowRequest($this, $otherDevice, 'GET', $type.'/me')
        ->assertOk();

    $freshToken = authFlowCsrf($this, $firstDevice);

    authFlowRequest(
        $this,
        $firstDevice,
        'POST',
        $type.'/login',
        [
            'email' => $user->email,
            'password' => 'OriginalPassword@123',
        ],
        $freshToken,
    )->assertUnauthorized();

    authFlowLogin(
        $this,
        $firstDevice,
        $type,
        $user,
        'ReplacementPassword@456',
    );

    authFlowRequest($this, $firstDevice, 'GET', $type.'/me')
        ->assertOk()
        ->assertJsonPath('data.user.role', $role);
})->with('auth flow account types');

it('keeps every email dependent route blocked without changing the account', function (
    string $type,
    string $role
) {
    $user = authFlowAccount($role, 'email-blocked@example.test');

    // Compare database-loaded snapshots on both sides.
    $original = $user->fresh()->getRawOriginal();

    $cookies = [];
    $token = authFlowLogin($this, $cookies, $type, $user);

    $requests = [
        'forgot-password' => [
            'email' => $user->email,
        ],
        'reset-password' => [
            'email' => $user->email,
            'token' => str_repeat('a', 64),
            'password' => 'ReplacementPassword@456',
            'password_confirmation' => 'ReplacementPassword@456',
        ],
        'email' => [
            'email' => 'changed-email@example.test',
            'current_password' => 'OriginalPassword@123',
        ],
        'email/verify' => [
            'token' => str_repeat('a', 64),
        ],
        'email/resend' => [],
    ];

    foreach ($requests as $path => $payload) {
        authFlowRequest(
            $this,
            $cookies,
            'POST',
            $type.'/'.$path,
            $payload,
            $token,
        )->assertForbidden();
    }

    expect($user->fresh()->getRawOriginal())->toBe($original);

    $this->assertDatabaseCount('password_reset_tokens', 0);
    $this->assertDatabaseCount('email_verification_tokens', 0);

    authFlowRequest($this, $cookies, 'GET', $type.'/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email);
})->with('auth flow account types');

it('enforces admin permissions independently of valid sessions and csrf tokens', function () {
    $customer = authFlowAccount('customer', 'flow-customer@example.test');
    $staff = authFlowAccount('staff', 'flow-staff@example.test');
    $admin = authFlowAccount('admin', 'flow-admin@example.test');

    $customerCookies = [];
    $staffCookies = [];
    $adminCookies = [];

    $customerToken = authFlowLogin(
        $this, $customerCookies, 'customer', $customer,
    );

    $staffToken = authFlowLogin(
        $this, $staffCookies, 'staff', $staff,
    );

    $adminToken = authFlowLogin(
        $this, $adminCookies, 'staff', $admin,
    );

    $payload = [
        'name' => 'Flow Created Staff',
        'email' => 'flow-created@example.test',
        'role' => 'staff',
        'password' => 'CreatedPassword@123',
        'password_confirmation' => 'CreatedPassword@123',
    ];

    authFlowRequest(
        $this,
        $customerCookies,
        'POST',
        'admin/users',
        $payload,
        $customerToken,
    )->assertUnauthorized();

    authFlowRequest(
        $this,
        $staffCookies,
        'POST',
        'admin/users',
        $payload,
        $staffToken,
    )->assertForbidden();

    $this->assertDatabaseCount('users', 3);

    authFlowRequest(
        $this,
        $adminCookies,
        'POST',
        'admin/users',
        $payload,
        $adminToken,
    )
        ->assertCreated()
        ->assertJsonPath('data.user.role', 'staff');

    $created = User::where('email', $payload['email'])->sole();
    $createdCookies = [];

    authFlowLogin(
        $this,
        $createdCookies,
        'staff',
        $created,
        'CreatedPassword@123',
    );

    authFlowRequest($this, $createdCookies, 'GET', 'staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $created->email);

    // Account creation must not replace the admin's session identity.
    authFlowRequest($this, $adminCookies, 'GET', 'staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $admin->email);

    $this->assertDatabaseCount('users', 4);
});
