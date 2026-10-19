<?php

use App\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

/**
 * These helpers are deliberately prefixed to avoid collisions with AuthFlowTest.
 * They use real HTTP routes, cookies, and the session-bound CSRF token.
 */
function staffManagementAccount(string $role = 'admin', array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'name' => 'Existing Operator',
        'email' => 'operator-'.str_replace('.', '', uniqid('', true)).'@example.test',
        'password' => Hash::make('OriginalPassword@123'),
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => null,
    ], $attributes));
}

function staffManagementCookies(TestResponse $response, array &$cookies): void
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->isCleared()) {
            unset($cookies[$cookie->getName()]);
        } else {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
    }
}

function staffManagementRequest(
    TestCase $test,
    array &$cookies,
    string $method,
    string $uri,
    array $data = [],
    ?string $csrf = null
): TestResponse {
    Auth::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');

    $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];

    if ($csrf !== null) {
        $headers['HTTP_X_CSRF_TOKEN'] = $csrf;
    }

    $response = $test->call(
        $method,
        $uri,
        [],
        $cookies,
        [],
        $headers,
        $method === 'GET' ? null : json_encode($data, JSON_THROW_ON_ERROR),
    );
    staffManagementCookies($response, $cookies);

    return $response;
}

function staffManagementLogin(TestCase $test, array &$cookies, User $admin): string
{
    $csrfResponse = staffManagementRequest($test, $cookies, 'GET', '/api/auth/csrf-token');
    $csrfResponse->assertOk();

    $csrf = $csrfResponse->json('data.csrf_token');
    expect($csrf)->toBeString()->not->toBe('');

    $login = staffManagementRequest($test, $cookies, 'POST', '/api/auth/staff/login', [
        'email' => $admin->email,
        'password' => 'OriginalPassword@123',
    ], $csrf);

    $login->assertOk()->assertJsonPath('data.user.email', $admin->email);
    $newCsrf = $login->json('data.csrf_token');
    expect($newCsrf)->toBeString()->not->toBe('');

    return $newCsrf;
}

function staffManagementPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Warehouse Staff',
        'email' => 'warehouse@example.test',
        'password' => 'OriginalPassword@123',
        'password_confirmation' => 'OriginalPassword@123',
        'role' => 'staff',
    ], $overrides);
}

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    $this->staffCookies = [];
    $this->staffAdmin = staffManagementAccount('admin');
    $this->staffCsrf = staffManagementLogin($this, $this->staffCookies, $this->staffAdmin);
});

it('creates an active staff account with a hashed password and no customer profile', function () {
    $response = staffManagementRequest($this, $this->staffCookies, 'POST', '/api/users',
        staffManagementPayload(['phone' => '+8801712345678', 'permissions' => ['inventory.adjust']]),
        $this->staffCsrf
    );

    $response->assertCreated()
        ->assertJsonPath('message', 'Success.')
        ->assertJsonPath('data.user.role', 'staff')
        ->assertJsonPath('data.user.is_active', true)
        ->assertJsonPath('data.user.email', 'warehouse@example.test')
        ->assertJsonPath('data.user.permissions', ['inventory.adjust']);

    $id = $response->json('data.user.id');
    expect($id)->toBeNumeric();

    $this->assertDatabaseHas('users', [
        'id' => $id,
        'email' => 'warehouse@example.test',
        'phone' => '+8801712345678',
        'role' => 'staff',
        'is_active' => true,
    ]);
    $this->assertDatabaseMissing('customers', ['user_id' => $id]);
    $this->assertDatabaseHas('staff_activity', [
        'actor_id' => $this->staffAdmin->id,
        'staff_id' => $id,
        'action' => 'created',
    ]);

    $created = User::findOrFail($id);
    expect(Hash::check('OriginalPassword@123', $created->password))->toBeTrue();
    expect($created->password)->not->toBe('OriginalPassword@123');

    foreach (['password', 'password_confirmation', 'access_version', 'remember_token', 'email_verified_at'] as $field) {
        $response->assertJsonMissingPath('data.user.'.$field);
    }
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('creates another admin when the caller is an authorized admin', function () {
    $response = staffManagementRequest($this, $this->staffCookies, 'POST', '/api/users',
        staffManagementPayload(['email' => 'second-admin@example.test', 'role' => 'admin']),
        $this->staffCsrf
    );

    $response->assertCreated()->assertJsonPath('data.user.role', 'admin');
    $this->assertDatabaseHas('users', ['email' => 'second-admin@example.test', 'role' => 'admin']);
});

it('normalizes new staff email addresses to lowercase', function () {
    staffManagementRequest($this, $this->staffCookies, 'POST', '/api/users',
        staffManagementPayload(['email' => 'Warehouse@Example.Test']), $this->staffCsrf
    )->assertCreated()->assertJsonPath('data.user.email', 'warehouse@example.test');
});

it('rejects missing required fields without creating an account', function (string $field) {
    $payload = staffManagementPayload(['email' => 'missing-'.$field.'@example.test']);
    unset($payload[$field]);
    $before = DB::table('users')->count();

    staffManagementRequest($this, $this->staffCookies, 'POST', '/api/users', $payload, $this->staffCsrf)
        ->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect(DB::table('users')->count())->toBe($before);
})->with(['name', 'email', 'password', 'password_confirmation', 'role']);

it('rejects invalid staff creation values', function (array $changes, string $field) {
    $payload = staffManagementPayload(array_merge(['email' => 'invalid-'.uniqid().'@example.test'], $changes));
    $before = DB::table('users')->count();

    staffManagementRequest($this, $this->staffCookies, 'POST', '/api/users', $payload, $this->staffCsrf)
        ->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect(DB::table('users')->count())->toBe($before);
})->with([
    'invalid role' => [['role' => 'customer'], 'role'],
    'invalid email' => [['email' => 'not-an-email'], 'email'],
    'overlong name' => [['name' => str_repeat('x', 151)], 'name'],
    'overlong email' => [['email' => str_repeat('a', 250).'@example.test'], 'email'],
    'invalid phone' => [['phone' => '01712345678'], 'phone'],
    'password mismatch' => [['password_confirmation' => 'DifferentPassword@123'], 'password'],
    'unknown permission' => [['permissions' => ['unknown.permission']], 'permissions.0'],
    'duplicate permission' => [['permissions' => ['catalog.view', 'catalog.view']], 'permissions.0'],
    'unexpected field' => [['is_active' => false], 'is_active'],
]);

it('rejects duplicate emails regardless of letter case', function () {
    staffManagementAccount('staff', ['email' => 'existing@example.test']);

    staffManagementRequest($this, $this->staffCookies, 'POST', '/api/users',
        staffManagementPayload(['email' => 'EXISTING@EXAMPLE.TEST']), $this->staffCsrf
    )->assertUnprocessable()->assertJsonValidationErrors(['email']);

    expect(User::whereRaw('LOWER(email) = ?', ['existing@example.test'])->count())->toBe(1);
});

it('rejects a duplicate phone number', function () {
    staffManagementAccount('staff', ['phone' => '+8801812345678']);

    staffManagementRequest($this, $this->staffCookies, 'POST', '/api/users',
        staffManagementPayload(['phone' => '+8801812345678']), $this->staffCsrf
    )->assertUnprocessable()->assertJsonValidationErrors(['phone']);

    expect(User::where('phone', '+8801812345678')->count())->toBe(1);
});

it('reads and updates a staff account using its numeric ID', function () {
    $target = staffManagementAccount('staff', ['email' => 'target@example.test']);
    $url = '/api/users/'.$target->id;

    staffManagementRequest($this, $this->staffCookies, 'GET', $url)
        ->assertOk()->assertJsonPath('data.user.id', $target->id);

    staffManagementRequest($this, $this->staffCookies, 'PATCH', $url, [
        'name' => 'Updated Operator',
        'phone' => '+8801912345678',
    ], $this->staffCsrf)
        ->assertOk()
        ->assertJsonPath('data.user.name', 'Updated Operator')
        ->assertJsonPath('data.user.phone', '+8801912345678');

    $this->assertDatabaseHas('users', [
        'id' => $target->id,
        'name' => 'Updated Operator',
        'phone' => '+8801912345678',
    ]);
});

it('rejects protected or unknown update fields without modifying the staff account', function (array $changes, string $field) {
    $target = staffManagementAccount('staff', ['email' => 'target@example.test']);
    $original = $target->fresh()->getRawOriginal();

    staffManagementRequest($this, $this->staffCookies, 'PATCH', '/api/users/'.$target->id,
        $changes, $this->staffCsrf
    )->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect($target->fresh()->getRawOriginal())->toEqual($original);
})->with([
    'password cannot be changed here' => [['password' => 'ReplacementPassword@456'], 'password'],
    'status cannot be changed here' => [['is_active' => false], 'is_active'],
    'ID cannot be changed' => [['id' => 99999], 'id'],
    'unknown field' => [['unexpected_field' => 'value'], 'unexpected_field'],
]);

it('returns 404 for nonexistent staff IDs and customer IDs', function () {
    $customer = staffManagementAccount('customer', ['email' => 'buyer@example.test']);
    DB::table('customers')->insert(['user_id' => $customer->id]);

    foreach ([$customer->id, 999999999] as $id) {
        staffManagementRequest($this, $this->staffCookies, 'GET', '/api/users/'.$id)->assertNotFound();
        staffManagementRequest($this, $this->staffCookies, 'PATCH', '/api/users/'.$id,
            ['name' => 'Should Not Change'], $this->staffCsrf
        )->assertNotFound();
    }
});

it('disables and reactivates a staff account without deleting it', function () {
    $target = staffManagementAccount('staff', ['email' => 'lifecycle@example.test']);
    $url = '/api/users/'.$target->id;

    staffManagementRequest($this, $this->staffCookies, 'POST', $url.'/disable', [], $this->staffCsrf)
        ->assertOk()->assertJsonPath('data.user.is_active', false);
    $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);

    staffManagementRequest($this, $this->staffCookies, 'POST', $url.'/reactivate', [], $this->staffCsrf)
        ->assertOk()->assertJsonPath('data.user.is_active', true);
    $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);
});

it('rejects unexpected body fields on status routes', function (string $action) {
    $target = staffManagementAccount('staff', ['email' => 'target@example.test']);
    $original = $target->fresh()->getRawOriginal();

    staffManagementRequest($this, $this->staffCookies, 'POST', '/api/users/'.$target->id.'/'.$action,
        ['role' => 'admin'], $this->staffCsrf
    )->assertUnprocessable()->assertJsonValidationErrors(['role']);

    expect($target->fresh()->getRawOriginal())->toEqual($original);
})->with(['disable', 'reactivate']);

it('denies staff creation to an authenticated staff member without staff.manage', function () {
    $staff = staffManagementAccount('staff', [
        'email' => 'restricted@example.test',
        'permissions' => [],
    ]);
    $cookies = [];
    $csrf = staffManagementLogin($this, $cookies, $staff);
    $before = DB::table('users')->count();

    staffManagementRequest($this, $cookies, 'POST', '/api/users',
        staffManagementPayload(['email' => 'forbidden@example.test']), $csrf
    )->assertForbidden();

    expect(DB::table('users')->count())->toBe($before);
});

it('rejects unauthenticated staff creation', function () {
    $cookies = [];
    $before = DB::table('users')->count();

    staffManagementRequest($this, $cookies, 'POST', '/api/users', staffManagementPayload())
        ->assertUnauthorized();

    expect(DB::table('users')->count())->toBe($before);
});
