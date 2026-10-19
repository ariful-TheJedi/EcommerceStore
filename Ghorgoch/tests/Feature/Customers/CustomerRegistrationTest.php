<?php

use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(LazilyRefreshDatabase::class);

it('registers through the Customers module URL', function () {
    $this->postJson('/api/customers/register', registrationPayload())
        ->assertCreated()
        ->assertJsonPath('data.user.role', 'customer')
        ->assertJsonPath('data.verification_sent', false);

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('customers', 1);
    $user = User::where('email', 'registration@example.test')->sole();
    $this->assertDatabaseHas('customers', ['user_id' => $user->id]);
    Notification::assertNothingSent();
});

it('rejects registration with 403 when customer registration is disabled', function () {
    config(['customers.registration_enabled' => false]);

    $this->postJson('/api/customers/register', registrationPayload())->assertForbidden();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('customers', 0);
    Notification::assertNothingSent();
});

it('limits customer registration to five attempts per minute', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/customers/register')->assertUnprocessable();
    }

    $this->postJson('/api/customers/register')->assertTooManyRequests();
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('customers', 0);
    Notification::assertNothingSent();
});

beforeEach(function () {
    // Stop before touching data if the test database is misconfigured.
    expect(app()->environment())->toBe('testing');
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');

    config([
        'customers.registration_enabled' => true,
        'auth.email_enabled' => false,
        'session.driver' => 'array',
    ]);

    Notification::fake();
});

function registrationPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Registration Test Customer',
        'email' => 'registration@example.test',
        'phone' => '+8801712345678',
        'password' => 'StrongPassword@123',
        'password_confirmation' => 'StrongPassword@123',
    ], $overrides);
}

function assertRegistrationRejected(
    $test,
    array $payload,
    string $field
): void {
    $usersBefore = DB::table('users')->count();
    $customersBefore = DB::table('customers')->count();

    $test->postJson('/api/customers/register', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(DB::table('users')->count())->toBe($usersBefore);
    expect(DB::table('customers')->count())->toBe($customersBefore);

    Notification::assertNothingSent();
}

it('registers an active unverified customer and creates its profile', function () {
    $payload = registrationPayload();

    $response = $this->postJson('/api/customers/register', $payload);

    $response
        ->assertCreated()
        ->assertJsonPath('data.user.email', $payload['email'])
        ->assertJsonPath('data.user.role', 'customer');

    $user = User::where('email', $payload['email'])->sole();

    expect($user->name)->toBe($payload['name']);
    expect($user->phone)->toBe($payload['phone']);
    expect($user->role)->toBe('customer');
    expect($user->is_active)->toBeTrue();
    expect($user->email_verified_at)->toBeNull();

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseHas('customers', [
        'user_id' => $user->id,
    ]);
});

it('stores a password hash instead of the submitted password', function () {
    $payload = registrationPayload();

    $this->postJson('/api/customers/register', $payload)
        ->assertCreated();

    $user = User::where('email', $payload['email'])->sole();

    expect($user->password)->not->toBe($payload['password']);
    expect(Hash::check($payload['password'], $user->password))->toBeTrue();
});

it('does not automatically log in a newly registered customer', function () {
    $this->postJson(
        '/api/customers/register',
        registrationPayload()
    )->assertCreated();

    $this->getJson('/api/auth/customer/me')
        ->assertUnauthorized();

    $this->getJson('/api/auth/staff/me')
        ->assertUnauthorized();
});

it('registers without an optional phone number', function () {
    $payload = registrationPayload();
    unset($payload['phone']);

    $this->postJson('/api/customers/register', $payload)
        ->assertCreated();

    $user = User::where('email', $payload['email'])->sole();

    expect($user->phone)->toBeNull();
});

it('allows different customers to omit their phone number', function () {
    foreach (['first@example.test', 'second@example.test'] as $email) {
        $payload = registrationPayload(['email' => $email]);
        unset($payload['phone']);

        $this->postJson('/api/customers/register', $payload)
            ->assertCreated();
    }

    $this->assertDatabaseCount('users', 2);
    $this->assertDatabaseCount('customers', 2);
});

it('succeeds when verification delivery is disabled and reports that no email was sent', function () {
    $this->postJson(
        '/api/customers/register',
        registrationPayload()
    )
        ->assertCreated()
        ->assertJsonPath('data.verification_sent', false);

    Notification::assertNothingSent();
});

it('does not expose passwords or internal token fields', function () {
    $response = $this->postJson(
        '/api/customers/register',
        registrationPayload()
    );

    $response->assertCreated();

    $forbiddenKeys = [
        'password',
        'password_confirmation',
        'remember_token',
        'token_hash',
        'verification_token',
        'reset_token',
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

it('rejects missing required fields', function (string $field) {
    $payload = registrationPayload();
    unset($payload[$field]);

    assertRegistrationRejected($this, $payload, $field);
})->with([
    'name',
    'email',
    'password',
    'password_confirmation',
]);

it('rejects invalid field values', function (string $field, mixed $value) {
    assertRegistrationRejected(
        $this,
        registrationPayload([$field => $value]),
        $field
    );
})->with([
    'null name' => ['name', null],
    'empty name' => ['name', ''],
    'whitespace name' => ['name', '   '],
    'array name' => ['name', ['attacker']],
    'numeric name' => ['name', 123],
    'name over 150 characters' => ['name', str_repeat('a', 151)],

    'null email' => ['email', null],
    'empty email' => ['email', ''],
    'malformed email' => ['email', 'not-an-email'],
    'email without domain' => ['email', 'person@'],
    'array email' => ['email', ['person@example.test']],
    'numeric email' => ['email', 123],
    'email over 255 characters' => [
        'email',
        str_repeat('a', 64).'@'.
        str_repeat('b', 63).'.'.
        str_repeat('c', 63).'.'.
        str_repeat('d', 63).'.test',
    ],
    'SQL fragment as email' => ['email', "' OR 1=1 --"],
    'HTML as email' => ['email', '<script>alert(1)</script>'],

    'array phone' => ['phone', ['+8801712345678']],
    'numeric phone' => ['phone', 1712345678],
    'malformed phone' => ['phone', 'not-a-phone'],
    'short phone' => ['phone', '+88017123'],
    'overlong phone' => ['phone', '+880171234567890123456789'],

    'null password' => ['password', null],
    'empty password' => ['password', ''],
    'array password' => ['password', ['StrongPassword@123']],
    'numeric password' => ['password', 123456789012],

    'null confirmation' => ['password_confirmation', null],
    'array confirmation' => [
        'password_confirmation',
        ['StrongPassword@123'],
    ],
]);

it('rejects weak or oversized passwords even when confirmation matches', function (string $password) {
    assertRegistrationRejected(
        $this,
        registrationPayload([
            'password' => $password,
            'password_confirmation' => $password,
        ]),
        'password'
    );
})->with([
    'eleven characters' => ['Aa1!aaaaaaa'],
    'no uppercase' => ['lowercase123!'],
    'no lowercase' => ['UPPERCASE123!'],
    'no number' => ['StrongPassword!'],
    'no symbol' => ['StrongPassword123'],
    'seventy three ASCII bytes' => ['Aa1!'.str_repeat('a', 69)],
    'multibyte password over seventy two bytes' => [
        'Aa1!'.str_repeat('é', 35),
    ],
]);

it('accepts passwords at the documented boundaries', function (string $password) {
    $this->postJson(
        '/api/customers/register',
        registrationPayload([
            'password' => $password,
            'password_confirmation' => $password,
        ])
    )->assertCreated();

    $user = User::where('email', 'registration@example.test')->sole();

    expect(Hash::check($password, $user->password))->toBeTrue();
})->with([
    'exactly twelve characters' => ['Aa1!aaaaaaaa'],
    'exactly seventy two ASCII bytes' => ['Aa1!'.str_repeat('a', 68)],
    'exactly seventy two multibyte bytes' => [
        'Aa1!'.str_repeat('é', 34),
    ],
]);

it('rejects mismatched password confirmation', function () {
    assertRegistrationRejected(
        $this,
        registrationPayload([
            'password_confirmation' => 'DifferentPassword@123',
        ]),
        'password'
    );
});

it('accepts a name of exactly 150 characters', function () {
    $name = str_repeat('a', 150);

    $this->postJson(
        '/api/customers/register',
        registrationPayload(['name' => $name])
    )->assertCreated();

    $this->assertDatabaseHas('users', ['name' => $name]);
});

it('rejects an email already held by any account type', function (string $role) {
    User::factory()->create([
        'email' => 'registration@example.test',
        'role' => $role,
    ]);

    assertRegistrationRejected(
        $this,
        registrationPayload(),
        'email'
    );
})->with(['customer', 'staff', 'admin']);

it('rejects a duplicate email with different letter casing', function () {
    User::factory()->create([
        'email' => 'registration@example.test',
    ]);

    assertRegistrationRejected(
        $this,
        registrationPayload([
            'email' => 'REGISTRATION@EXAMPLE.TEST',
        ]),
        'email'
    );
});

it('rejects a duplicate phone number', function () {
    User::factory()->create([
        'email' => 'existing@example.test',
        'phone' => '+8801712345678',
    ]);

    assertRegistrationRejected(
        $this,
        registrationPayload(),
        'phone'
    );
});

it('rejects unexpected fields instead of accepting injected account attributes', function (
    string $field,
    mixed $value
) {
    assertRegistrationRejected(
        $this,
        registrationPayload([$field => $value]),
        $field
    );
})->with([
    'admin role' => ['role', 'admin'],
    'staff role' => ['role', 'staff'],
    'even a customer role is not an accepted input' => ['role', 'customer'],
    'user id' => ['user_id', 1],
    'primary key' => ['id', 1],
    'permissions' => ['permissions', ['*']],
    'active flag' => ['is_active', true],
    'verified email timestamp' => [
        'email_verified_at',
        '2026-01-01T00:00:00Z',
    ],
    'avatar path' => ['avatar_path', '/private/file'],
    'nested account data' => ['account', ['role' => 'admin']],
    'unexpected ordinary field' => ['unexpected', 'value'],
]);

it('does not overwrite an existing account when registration conflicts', function () {
    $existing = User::factory()->create([
        'email' => 'registration@example.test',
        'name' => 'Existing Administrator',
        'role' => 'admin',
    ]);

    $originalPassword = $existing->password;

    assertRegistrationRejected(
        $this,
        registrationPayload(),
        'email'
    );

    $existing->refresh();

    expect($existing->name)->toBe('Existing Administrator');
    expect($existing->role)->toBe('admin');
    expect($existing->password)->toBe($originalPassword);
});

it('rejects a replayed registration without creating extra records', function () {
    $payload = registrationPayload();

    $this->postJson('/api/customers/register', $payload)
        ->assertCreated();

    assertRegistrationRejected($this, $payload, 'email');

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('customers', 1);
});
