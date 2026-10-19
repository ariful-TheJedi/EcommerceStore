<?php

use App\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * End-to-end Auth -> Users integration using real HTTP routes, cookie sessions,
 * CSRF tokens and database assertions. No mocks and no dependency on other test files.
 */
function usersFlowAccount(string $role, array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'name' => 'Integration Operator',
        'email' => 'flow-'.uniqid('', true).'@example.test',
        'password' => Hash::make('OriginalPassword@123'),
        'role' => $role,
        'permissions' => $role === 'staff' ? [] : null,
        'is_active' => true,
        'email_verified_at' => null,
    ], $attributes));
}

function usersFlowCookies(TestResponse $response, array &$cookies): void
{
    foreach ($response->headers->getCookies() as $cookie) {
        $expires = $cookie->getExpiresTime();
        if ($cookie->isCleared() || ($expires !== 0 && $expires <= time())) {
            unset($cookies[$cookie->getName()]);
        } else {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
    }
}

function usersFlowRequest(
    $test,
    array &$cookies,
    string $method,
    string $uri,
    array $payload = [],
    ?string $csrf = null
): TestResponse {
    $headers = ['Accept' => 'application/json'];

    if ($csrf !== null) {
        $headers['X-CSRF-TOKEN'] = $csrf;
    }

    // Isolate each browser's cookie jar and the cached auth guard.
    // Do NOT forget session drivers: doing so loses the in-memory
    // session state used by the Laravel test runner and causes 401s.
    $cookieProperty = new ReflectionProperty($test, 'defaultCookies');
    $cookieProperty->setValue($test, []);

    app('auth')->forgetGuards();

    $response = $test->withCookies($cookies)->json($method, $uri, $payload, $headers);
    usersFlowCookies($response, $cookies);

    return $response;
}

function usersFlowLogin($test, array &$cookies, User $account): string
{
    $csrfResponse = usersFlowRequest($test, $cookies, 'GET', '/api/auth/csrf-token');
    $csrfResponse->assertOk();

    $login = usersFlowRequest($test, $cookies, 'POST', '/api/auth/staff/login', [
        'email' => $account->email,
        'password' => 'OriginalPassword@123',
    ], $csrfResponse->json('data.csrf_token'));
    $login->assertOk()->assertJsonPath('data.user.email', $account->email);

    $token = $login->json('data.csrf_token');
    expect($token)->toBeString()->not->toBe('');

    return $token;
}

function usersFlowDenied(TestResponse $response): void
{
    expect($response->status())->toBeIn([401, 403]);
}

function usersFlowNoSecrets(TestResponse $response): void
{
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->headers->get('Cache-Control'))->toContain('private');

    $json = $response->getContent();
    foreach (['"password"', '"remember_token"', '"access_version"', '"password_confirmation"'] as $key) {
        expect($json)->not->toContain($key);
    }
}

it('completes admin creation, directory, updates, status, and audit using one authenticated browser', function () {
    $admin = usersFlowAccount('admin');
    $cookies = [];
    $csrf = usersFlowLogin($this, $cookies, $admin);

    $me = usersFlowRequest($this, $cookies, 'GET', '/api/users/me');
    $me->assertOk()->assertJsonPath('data.user.id', $admin->id);
    usersFlowNoSecrets($me);

    usersFlowRequest($this, $cookies, 'GET', '/api/users/capabilities')
        ->assertOk()->assertJsonStructure(['data' => ['roles', 'permissions']]);

    $created = usersFlowRequest($this, $cookies, 'POST', '/api/users', [
        'name' => 'Fulfillment Agent',
        'email' => 'FLOW.STAFF@example.test',
        'password' => 'OriginalPassword@123',
        'password_confirmation' => 'OriginalPassword@123',
        'role' => 'staff',
        'permissions' => ['staff.view'],
    ], $csrf);

    $created->assertCreated()
        ->assertJsonPath('data.user.email', 'flow.staff@example.test')
        ->assertJsonPath('data.user.is_active', true);
    usersFlowNoSecrets($created);

    $id = $created->json('data.user.id');
    expect($id)->toBeNumeric();
    $this->assertDatabaseHas('users', ['id' => $id, 'role' => 'staff', 'is_active' => true]);
    $this->assertDatabaseMissing('customers', ['user_id' => $id]);
    expect(Hash::check('OriginalPassword@123', User::findOrFail($id)->password))->toBeTrue();

    usersFlowRequest($this, $cookies, 'GET', '/api/users?page=1&per_page=10&role=staff&status=active&search=flow.staff')
        ->assertOk()->assertJsonStructure(['data' => ['staff', 'pagination']]);
    usersFlowRequest($this, $cookies, 'GET', '/api/users/'.$id)
        ->assertOk()->assertJsonPath('data.user.id', $id);

    usersFlowRequest($this, $cookies, 'PATCH', '/api/users/'.$id, [
        'name' => 'Fulfillment Supervisor',
        'phone' => '+8801712345678',
        'permissions' => ['staff.view', 'catalog.view'],
    ], $csrf)->assertOk()->assertJsonPath('data.user.name', 'Fulfillment Supervisor');
    $this->assertDatabaseHas('users', ['id' => $id, 'phone' => '+8801712345678']);

    usersFlowRequest($this, $cookies, 'POST', '/api/users/'.$id.'/disable', [], $csrf)
        ->assertOk()->assertJsonPath('data.user.is_active', false);
    usersFlowRequest($this, $cookies, 'POST', '/api/users/'.$id.'/reactivate', [], $csrf)
        ->assertOk()->assertJsonPath('data.user.is_active', true);

    $history = usersFlowRequest($this, $cookies, 'GET', '/api/users/'.$id.'/activity?page=1&per_page=20');
    $history->assertOk()->assertJsonStructure(['data' => ['activity', 'pagination']]);
    expect(count($history->json('data.activity')))->toBeGreaterThanOrEqual(4);
    $this->assertDatabaseHas('staff_activity', [
        'actor_id' => $admin->id,
        'staff_id' => $id,
        'action' => 'created',
    ]);
    $this->assertDatabaseHas('users', ['id' => $id, 'is_active' => true]);
});

it('rejects anonymous requests, unauthorized staff actions and invalid admin operations', function () {
    // Anonymous request comes first, before any login in this test.
    $cookies = [];
    usersFlowRequest($this, $cookies, 'GET', '/api/users')->assertUnauthorized();

    $admin = usersFlowAccount('admin');
    $staff = usersFlowAccount('staff', ['permissions' => []]);
    $customer = usersFlowAccount('customer');
    DB::table('customers')->insert(['user_id' => $customer->id]);

    // All admin operations occur before signing in as the lower-privilege user.
    $adminCookies = [];
    $csrf = usersFlowLogin($this, $adminCookies, $admin);
    usersFlowRequest($this, $adminCookies, 'GET', '/api/users/'.$customer->id)
        ->assertNotFound();
    usersFlowRequest($this, $adminCookies, 'GET', '/api/users?per_page=101')
        ->assertUnprocessable();
    usersFlowRequest($this, $adminCookies, 'GET', '/api/users/999999999')
        ->assertNotFound();

    $snapshot = $staff->fresh()->getRawOriginal();
    usersFlowRequest($this, $adminCookies, 'PATCH', '/api/users/'.$staff->id, [
        'name' => 'Should Reject',
        'password' => 'InsecurePassword123',
    ], $csrf)->assertUnprocessable();
    expect($staff->fresh()->getRawOriginal())->toEqual($snapshot);

    // Switch identity once, never reuse the previous admin session in this test.
    $staffCookies = [];
    $staffCsrf = usersFlowLogin($this, $staffCookies, $staff);
    usersFlowRequest($this, $staffCookies, 'GET', '/api/users')->assertForbidden();
    usersFlowRequest($this, $staffCookies, 'GET', '/api/users/capabilities')->assertForbidden();
    usersFlowRequest($this, $staffCookies, 'PATCH', '/api/users/'.$admin->id, [
        'name' => 'Unauthorized Admin Edit',
    ], $staffCsrf)->assertForbidden();
    usersFlowRequest($this, $staffCookies, 'POST', '/api/users', [
        'name' => 'Forbidden Creation',
        'email' => 'forbidden@example.test',
        'password' => 'OriginalPassword@123',
        'password_confirmation' => 'OriginalPassword@123',
        'role' => 'staff',
    ], $staffCsrf)->assertForbidden();
    $this->assertDatabaseMissing('users', ['email' => 'forbidden@example.test']);
    $this->assertDatabaseHas('users', ['id' => $admin->id, 'name' => $admin->name]);
});

it('protects the final active administrator from deactivation and demotion', function () {
    $admin = usersFlowAccount('admin');
    $cookies = [];
    $csrf = usersFlowLogin($this, $cookies, $admin);
    $original = $admin->fresh()->getRawOriginal();

    usersFlowRequest($this, $cookies, 'POST', '/api/users/'.$admin->id.'/disable', [], $csrf)
        ->assertStatus(409);
    usersFlowRequest($this, $cookies, 'PATCH', '/api/users/'.$admin->id, [
        'role' => 'staff',
    ], $csrf)->assertStatus(409);

    expect($admin->fresh()->getRawOriginal())->toEqual($original);
});

it('applies newly reduced permissions on the next authenticated login', function () {
    $admin = usersFlowAccount('admin');
    $worker = usersFlowAccount('staff', ['permissions' => ['staff.view']]);
    $cookies = [];
    $csrf = usersFlowLogin($this, $cookies, $admin);

    // The worker's initial permission and subsequent removal are checked in DB.
    expect($worker->fresh()->permissions)->toContain('staff.view');
    usersFlowRequest($this, $cookies, 'PATCH', '/api/users/'.$worker->id, [
        'permissions' => [],
    ], $csrf)->assertOk();
    expect($worker->fresh()->permissions ?? [])->toBe([]);

    // Switch to the worker and check the live permission decision.
    $workerCookies = [];
    usersFlowLogin($this, $workerCookies, $worker->fresh());
    usersFlowRequest($this, $workerCookies, 'GET', '/api/users')->assertForbidden();
    usersFlowRequest($this, $workerCookies, 'GET', '/api/users/me')->assertOk();
});
