<?php

use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    config(['session.driver' => 'database', 'session.lottery' => [0, 100],
        'auth.customer_enabled' => true, 'auth.email_enabled' => false]);

    /** Disable Laravel's test-only bypass, retaining the real CSRF middleware. */
    $this->app->instance('env', 'local');
    expect($this->app->runningUnitTests())->toBeFalse();
});

/** Replay raw encrypted cookies and discard per-request services, like separate HTTP requests. */
function authCsrfRequest(TestCase $test, array &$cookies, string $method, string $path, array $data = [], ?string $token = null, array $headers = []): TestResponse
{
    Auth::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', ...$headers];
    if ($token !== null) {
        $server['HTTP_X_CSRF_TOKEN'] = $token;
    }
    $response = $test->call($method, (str_starts_with($path, '/') ? $path : '/api/auth/'.$path), [], $cookies, [], $server, json_encode($data, JSON_THROW_ON_ERROR));
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === config('session.cookie')) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
    }

    return $response;
}

dataset('auth csrf posts', function (): array {
    $routes = [
        'admin/users' => ['admin/users', 401],
    ];
    foreach (['customer', 'staff'] as $type) {
        foreach (['login' => 422, 'forgot-password' => 403, 'reset-password' => 403,
            'logout' => 401, 'logout-all' => 401, 'password' => 401,
            'email' => 401, 'email/verify' => 401, 'email/resend' => 401] as $path => $status) {
            $routes[$type.'/'.$path] = [$type.'/'.$path, $status];
        }
    }

    return $routes;
});

it('rejects missing wrong and foreign tokens on every POST with JSON 419', function (string $path, int $status, string $kind, array $headers) {
    $cookies = [];
    authCsrfRequest($this, $cookies, 'GET', 'csrf-token')->assertOk();
    $token = $kind === 'missing' ? null : 'invalid-token';
    if ($kind === 'foreign') {
        $otherCookies = [];
        $token = authCsrfRequest($this, $otherCookies, 'GET', 'csrf-token')->assertOk()->json('data.csrf_token');
    }

    authCsrfRequest($this, $cookies, 'POST', $path, token: $token, headers: $headers)
        ->assertStatus(419)->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['message' => 'CSRF token mismatch. Fetch a new CSRF token.', 'data' => []]);
})->with('auth csrf posts')->with(['missing', 'wrong', 'foreign'])->with([
    'no fetch metadata' => [[]], 'same origin' => [['HTTP_SEC_FETCH_SITE' => 'same-origin']],
]);

it('lets matching cookie and token reach normal validation flags or authentication', function (string $path, int $status) {
    $cookies = [];
    $token = authCsrfRequest($this, $cookies, 'GET', 'csrf-token')->assertOk()->json('data.csrf_token');

    authCsrfRequest($this, $cookies, 'POST', $path, token: $token)->assertStatus($status);
})->with('auth csrf posts');

it('rejects a token without its session cookie', function () {
    $cookies = [];
    $token = authCsrfRequest($this, $cookies, 'GET', 'csrf-token')->assertOk()->json('data.csrf_token');
    $noCookies = [];
    authCsrfRequest($this, $noCookies, 'POST', 'customer/login', token: $token)->assertStatus(419);
});

it('rotates login and logout sessions and tokens while preserving role separation', function (string $type, string $role, string $logout) {
    $user = User::factory()->create(['role' => $role]);
    $credentials = ['email' => $user->email, 'password' => 'password'];
    $cookies = [];
    $oldToken = authCsrfRequest($this, $cookies, 'GET', 'csrf-token')->assertOk()->json('data.csrf_token');
    $oldSession = session()->getId();
    $oldCookies = $cookies;
    $login = authCsrfRequest($this, $cookies, 'POST', $type.'/login', $credentials, $oldToken)->assertOk();
    $token = $login->json('data.csrf_token');
    $loginSession = session()->getId();
    expect($loginSession)->not->toBe($oldSession);
    expect($token)->not->toBe($oldToken)->toBe(session()->token());
    $this->assertDatabaseMissing('sessions', ['id' => $oldSession]);
    authCsrfRequest($this, $oldCookies, 'GET', $type.'/me')->assertUnauthorized();
    authCsrfRequest($this, $cookies, 'GET', $type.'/me')->assertOk();
    authCsrfRequest($this, $cookies, 'GET', ($type === 'staff' ? 'customer' : 'staff').'/me')->assertUnauthorized();
    authCsrfRequest($this, $cookies, 'POST', $type.'/'.$logout, token: $oldToken)->assertStatus(419);
    authCsrfRequest($this, $cookies, 'POST', $type.'/'.$logout, token: $token)->assertOk();
    expect(session()->getId())->not->toBe($loginSession);
    expect(session()->token())->not->toBe($token);
    $this->assertDatabaseMissing('sessions', ['id' => $loginSession]);
    authCsrfRequest($this, $cookies, 'GET', $type.'/me')->assertUnauthorized();
    authCsrfRequest($this, $cookies, 'POST', $type.'/login', $credentials, $token)->assertStatus(419);
    $freshToken = authCsrfRequest($this, $cookies, 'GET', 'csrf-token')->assertOk()->json('data.csrf_token');
    authCsrfRequest($this, $cookies, 'POST', $type.'/login', $credentials, $freshToken)->assertOk();
})->with(['customer' => ['customer', 'customer'], 'staff' => ['staff', 'staff']])->with(['logout', 'logout-all']);

it('requires admin permissions even with a valid csrf token', function () {
    $user = User::factory()->create(['role' => 'staff']);
    $cookies = [];
    $token = authCsrfRequest($this, $cookies, 'GET', 'csrf-token')->assertOk()->json('data.csrf_token');
    $token = authCsrfRequest($this, $cookies, 'POST', 'staff/login', ['email' => $user->email, 'password' => 'password'], $token)
        ->assertOk()->json('data.csrf_token');

    authCsrfRequest($this, $cookies, 'POST', 'admin/users', token: $token)->assertForbidden();
    $this->assertDatabaseCount('users', 1);
});

it('allows an authenticated admin to create a user with matching cookie and csrf token', function () {
    Notification::fake();
    $user = User::factory()->create(['role' => 'admin']);
    $cookies = [];
    $token = authCsrfRequest($this, $cookies, 'GET', 'csrf-token')->assertOk()->json('data.csrf_token');
    $token = authCsrfRequest($this, $cookies, 'POST', 'staff/login', ['email' => $user->email, 'password' => 'password'], $token)
        ->assertOk()->json('data.csrf_token');

    authCsrfRequest($this, $cookies, 'POST', 'admin/users', [
        'name' => 'CSRF Test User', 'email' => 'csrf-user@example.test', 'role' => 'customer',
        'password' => 'StrongPassword@123', 'password_confirmation' => 'StrongPassword@123',
    ], $token)->assertCreated()->assertJsonPath('data.user.role', 'customer');
    $created = User::where('email', 'csrf-user@example.test')->firstOrFail();
    expect(Hash::check('StrongPassword@123', $created->password))->toBeTrue();
    $this->assertDatabaseHas('customers', ['user_id' => $created->id]);
    Notification::assertNothingSent();
});

it('keeps absolute session expiry and account revocation effective', function () {
    $user = User::factory()->create();
    $cookies = [];
    $token = authCsrfRequest($this, $cookies, 'GET', 'csrf-token')->assertOk()->json('data.csrf_token');
    authCsrfRequest($this, $cookies, 'POST', 'customer/login', ['email' => $user->email, 'password' => 'password'], $token)->assertOk();
    $sessionId = session()->getId();
    $this->travel(config('auth.session_max_minutes') + 1)->minutes();
    DB::table('sessions')->where('id', $sessionId)->update(['last_activity' => now()->timestamp]);
    authCsrfRequest($this, $cookies, 'GET', 'customer/me')->assertUnauthorized();
    $this->travelBack();
    $token = authCsrfRequest($this, $cookies, 'GET', 'csrf-token')->assertOk()->json('data.csrf_token');
    $token = authCsrfRequest($this, $cookies, 'POST', 'customer/login', ['email' => $user->email, 'password' => 'password'], $token)
        ->assertOk()->json('data.csrf_token');
    $user->is_active = false;
    $user->save();

    authCsrfRequest($this, $cookies, 'POST', 'customer/logout', token: $token)->assertStatus(419);
    authCsrfRequest($this, $cookies, 'GET', 'customer/me')->assertUnauthorized();
});

it('returns JSON 419 even when the client asks for HTML', function () {
    $cookies = [];
    authCsrfRequest($this, $cookies, 'POST', 'customer/login', headers: ['HTTP_ACCEPT' => 'text/html'])
        ->assertStatus(419)->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['message' => 'CSRF token mismatch. Fetch a new CSRF token.', 'data' => []]);
});
