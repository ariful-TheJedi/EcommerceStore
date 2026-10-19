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
});

function adminCreationPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Created Account',
        'email' => 'created-account@example.test',
        'phone' => '+8801712345678',
        'password' => 'CreatedPassword@123',
        'password_confirmation' => 'CreatedPassword@123',
        'role' => 'staff',
    ], $overrides);
}

/**
 * Replay raw cookies and reload authentication/session state.
 * CSRF enforcement is tested separately.
 */
function adminCreationRequest(
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

function adminCreationLogin(
    TestCase $test,
    array &$cookies,
    string $role = 'admin'
): User {
    $user = User::factory()->create([
        'email' => 'acting-account@example.test',
        'password' => Hash::make('ActingPassword@123'),
        'role' => $role,
        'is_active' => true,
    ]);

    if ($role === 'customer') {
        DB::table('customers')->insert(['user_id' => $user->id]);
    }

    adminCreationRequest(
        $test,
        $cookies,
        'POST',
        $role === 'customer' ? 'customer/login' : 'staff/login',
        [
            'email' => $user->email,
            'password' => 'ActingPassword@123',
        ],
    )->assertOk();

    return $user;
}

function assertAdminCreationRejected(
    TestCase $test,
    array $payload,
    string $field
): void {
    $cookies = [];
    $admin = adminCreationLogin($test, $cookies);

    $usersBefore = DB::table('users')->count();
    $customersBefore = DB::table('customers')->count();

    adminCreationRequest(
        $test, $cookies, 'POST', 'admin/users', $payload
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(DB::table('users')->count())->toBe($usersBefore);
    expect(DB::table('customers')->count())->toBe($customersBefore);

    adminCreationRequest($test, $cookies, 'GET', 'staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $admin->email);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
}

it('allows an admin to create each supported account type', function (
    string $role
) {
    $cookies = [];
    $admin = adminCreationLogin($this, $cookies);

    $response = adminCreationRequest(
        $this,
        $cookies,
        'POST',
        'admin/users',
        adminCreationPayload(['role' => $role]),
    );

    $response
        ->assertCreated()
        ->assertJsonPath('data.user.email', 'created-account@example.test')
        ->assertJsonPath('data.user.role', $role);

    $created = User::where('email', 'created-account@example.test')->sole();

    expect($created->name)->toBe('Created Account');
    expect($created->phone)->toBe('+8801712345678');
    expect($created->role)->toBe($role);

    expect(Hash::check('CreatedPassword@123', $created->password))
        ->toBeTrue();

    $this->assertDatabaseCount('users', 2);

    if ($role === 'customer') {
        $this->assertDatabaseHas('customers', [
            'user_id' => $created->id,
        ]);
        $this->assertDatabaseCount('customers', 1);
    } else {
        $this->assertDatabaseMissing('customers', [
            'user_id' => $created->id,
        ]);
    }

    // Creating an account must not switch the admin's session.
    adminCreationRequest($this, $cookies, 'GET', 'staff/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $admin->email)
        ->assertJsonPath('data.user.role', 'admin');

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
})->with(['staff', 'admin', 'customer']);

it('allows account creation without an optional phone', function () {
    $cookies = [];
    adminCreationLogin($this, $cookies);

    $payload = adminCreationPayload();
    unset($payload['phone']);

    adminCreationRequest(
        $this, $cookies, 'POST', 'admin/users', $payload
    )->assertCreated();

    expect(
        User::where('email', $payload['email'])->sole()->phone
    )->toBeNull();
});

it('rejects unauthenticated requests without creating records', function () {
    $cookies = [];

    adminCreationRequest(
        $this,
        $cookies,
        'POST',
        'admin/users',
        adminCreationPayload(),
    )->assertUnauthorized();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('customers', 0);
});

it('rejects a staff account even with a valid staff session', function () {
    $cookies = [];
    $staff = adminCreationLogin($this, $cookies, 'staff');

    adminCreationRequest(
        $this,
        $cookies,
        'POST',
        'admin/users',
        adminCreationPayload(['role' => 'admin']),
    )->assertForbidden();

    $this->assertDatabaseCount('users', 1);
    expect($staff->fresh()->role)->toBe('staff');

    adminCreationRequest($this, $cookies, 'GET', 'staff/me')
        ->assertOk();
});

it('rejects a customer session', function () {
    $cookies = [];
    adminCreationLogin($this, $cookies, 'customer');

    adminCreationRequest(
        $this,
        $cookies,
        'POST',
        'admin/users',
        adminCreationPayload(),
    )->assertUnauthorized();

    $this->assertDatabaseCount('users', 1);

    adminCreationRequest($this, $cookies, 'GET', 'customer/me')
        ->assertOk();
});

it('rejects a forged session cookie', function () {
    $cookies = [
        config('session.cookie') => 'attacker-controlled-cookie',
    ];

    adminCreationRequest(
        $this,
        $cookies,
        'POST',
        'admin/users',
        adminCreationPayload(),
    )->assertUnauthorized();

    $this->assertDatabaseCount('users', 0);
});

it('rejects an admin disabled after login', function () {
    $cookies = [];
    $admin = adminCreationLogin($this, $cookies);

    $admin->is_active = false;
    $admin->save();

    adminCreationRequest(
        $this,
        $cookies,
        'POST',
        'admin/users',
        adminCreationPayload(),
    )->assertUnauthorized();

    $this->assertDatabaseCount('users', 1);
});

it('rejects an old session after admin demotion', function () {
    $cookies = [];
    $admin = adminCreationLogin($this, $cookies);

    $admin->role = 'staff';
    $admin->save();

    adminCreationRequest(
        $this,
        $cookies,
        'POST',
        'admin/users',
        adminCreationPayload(),
    )->assertUnauthorized();

    $this->assertDatabaseCount('users', 1);
});

it('rejects missing required fields', function (string $field) {
    $payload = adminCreationPayload();
    unset($payload[$field]);

    assertAdminCreationRejected($this, $payload, $field);
})->with([
    'name',
    'email',
    'password',
    'password_confirmation',
    'role',
]);

it('rejects invalid account fields', function (string $field, mixed $value) {
    assertAdminCreationRejected(
        $this,
        adminCreationPayload([$field => $value]),
        $field,
    );
})->with([
    'empty name' => ['name', ''],
    'whitespace name' => ['name', '   '],
    'array name' => ['name', ['invalid']],
    'name too long' => ['name', str_repeat('a', 151)],
    'invalid email' => ['email', 'not-an-email'],
    'array email' => ['email', ['invalid@example.test']],
    'null email' => ['email', null],
    'invalid phone' => ['phone', 'invalid-phone'],
    'array phone' => ['phone', ['+8801712345678']],
    'unsupported role' => ['role', 'super_admin'],
    'uppercase role' => ['role', 'ADMIN'],
    'empty role' => ['role', ''],
    'null role' => ['role', null],
    'array role' => ['role', ['admin']],
    'numeric role' => ['role', 1],
    'array password' => ['password', ['invalid']],
    'null password' => ['password', null],
    'array confirmation' => ['password_confirmation', ['invalid']],
]);

it('rejects weak or oversized passwords', function (string $password) {
    assertAdminCreationRejected(
        $this,
        adminCreationPayload([
            'password' => $password,
            'password_confirmation' => $password,
        ]),
        'password',
    );
})->with([
    'eleven characters' => ['Aa1!aaaaaaa'],
    'no uppercase' => ['lowercase123!'],
    'no lowercase' => ['UPPERCASE123!'],
    'no number' => ['StrongPassword!'],
    'no symbol' => ['StrongPassword123'],
    'over 72 ASCII bytes' => ['Aa1!'.str_repeat('a', 69)],
    'over 72 multibyte bytes' => ['Aa1!'.str_repeat('é', 35)],
]);

it('accepts valid password boundaries', function (string $password) {
    $cookies = [];
    adminCreationLogin($this, $cookies);

    adminCreationRequest(
        $this,
        $cookies,
        'POST',
        'admin/users',
        adminCreationPayload([
            'password' => $password,
            'password_confirmation' => $password,
        ]),
    )->assertCreated();

    $created = User::where('email', 'created-account@example.test')->sole();

    expect(Hash::check($password, $created->password))->toBeTrue();
})->with([
    '12 characters' => ['Aa1!aaaaaaaa'],
    '72 ASCII bytes' => ['Aa1!'.str_repeat('a', 68)],
    '72 multibyte bytes' => ['Aa1!'.str_repeat('é', 34)],
]);

it('rejects mismatched confirmation', function () {
    assertAdminCreationRejected(
        $this,
        adminCreationPayload([
            'password_confirmation' => 'DifferentPassword@123',
        ]),
        'password',
    );
});

it('rejects duplicate email across account types', function (string $role) {
    User::factory()->create([
        'email' => 'taken@example.test',
        'role' => $role,
    ]);

    assertAdminCreationRejected(
        $this,
        adminCreationPayload(['email' => 'taken@example.test']),
        'email',
    );
})->with(['customer', 'staff', 'admin']);

it('rejects a duplicate email with different casing', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    assertAdminCreationRejected(
        $this,
        adminCreationPayload(['email' => 'TAKEN@EXAMPLE.TEST']),
        'email',
    );
});

it('rejects duplicate phone numbers', function () {
    User::factory()->create([
        'email' => 'phone-owner@example.test',
        'phone' => '+8801712345678',
    ]);

    assertAdminCreationRejected(
        $this,
        adminCreationPayload(),
        'phone',
    );
});

it('rejects unexpected fields despite admin privileges', function (
    string $field,
    mixed $value
) {
    assertAdminCreationRejected(
        $this,
        adminCreationPayload([$field => $value]),
        $field,
    );
})->with([
    'primary key' => ['id', 999999],
    'user id' => ['user_id', 999999],
    'permissions' => ['permissions', ['*']],
    'active status' => ['is_active', false],
    'verified timestamp' => [
        'email_verified_at', '2026-01-01T00:00:00Z',
    ],
    'nested account' => ['account', ['role' => 'admin']],
    'arbitrary field' => ['unexpected', 'value'],
]);

it('rejects replayed creation without duplicating accounts', function () {
    $cookies = [];
    adminCreationLogin($this, $cookies);

    $payload = adminCreationPayload();

    adminCreationRequest(
        $this, $cookies, 'POST', 'admin/users', $payload
    )->assertCreated();

    adminCreationRequest(
        $this, $cookies, 'POST', 'admin/users', $payload
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    $this->assertDatabaseCount('users', 2);
});

it('does not expose passwords or password hashes', function () {
    $cookies = [];
    adminCreationLogin($this, $cookies);

    $response = adminCreationRequest(
        $this,
        $cookies,
        'POST',
        'admin/users',
        adminCreationPayload(),
    );

    $response->assertCreated();

    $created = User::where('email', 'created-account@example.test')->sole();

    $response->assertJsonMissingPath('data.user.password');

    expect($response->getContent())
        ->not->toContain('CreatedPassword@123')
        ->not->toContain($created->password);
});
