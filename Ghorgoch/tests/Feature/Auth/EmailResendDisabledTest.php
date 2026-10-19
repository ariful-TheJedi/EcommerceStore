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

function resendDisabledRequest(
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

it('blocks resend without changing the account token or authenticated session', function (
    string $type,
    string $role
) {
    $user = User::factory()->create([
        'email' => 'resend-disabled@example.test',
        'password' => Hash::make('AccountPassword@123'),
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => null,
    ]);

    if ($role === 'customer') {
        DB::table('customers')->insert(['user_id' => $user->id]);
    }

    DB::table('email_verification_tokens')->insert([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', str_repeat('a', 64)),
        'expires_at' => now()->addHour(),
        'created_at' => now(),
    ]);

    $originalAccount = $user->fresh()->getRawOriginal();

    $originalToken = (array) DB::table('email_verification_tokens')
        ->where('user_id', $user->id)
        ->first();

    $cookies = [];

    resendDisabledRequest(
        $this,
        $cookies,
        'POST',
        $type.'/login',
        [
            'email' => $user->email,
            'password' => 'AccountPassword@123',
        ],
    )->assertOk();

    resendDisabledRequest(
        $this,
        $cookies,
        'POST',
        $type.'/email/resend',
    )
        ->assertForbidden()
        ->assertJsonStructure(['message', 'data']);

    expect($user->fresh()->getRawOriginal())->toBe($originalAccount);

    expect((array) DB::table('email_verification_tokens')
        ->where('user_id', $user->id)
        ->first())->toBe($originalToken);

    resendDisabledRequest($this, $cookies, 'GET', $type.'/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
})->with([
    'customer' => ['customer', 'customer'],
    'staff' => ['staff', 'staff'],
    'admin' => ['staff', 'admin'],
]);

it('requires authentication for resend', function (string $type) {
    $cookies = [];

    resendDisabledRequest(
        $this,
        $cookies,
        'POST',
        $type.'/email/resend',
    )->assertUnauthorized();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
})->with(['customer', 'staff']);

it('does not create verification tokens when resend is disabled', function (
    string $type,
    string $role
) {
    $user = User::factory()->create([
        'email' => 'no-resend-token@example.test',
        'password' => Hash::make('AccountPassword@123'),
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => null,
    ]);

    if ($role === 'customer') {
        DB::table('customers')->insert(['user_id' => $user->id]);
    }

    $cookies = [];

    resendDisabledRequest(
        $this,
        $cookies,
        'POST',
        $type.'/login',
        [
            'email' => $user->email,
            'password' => 'AccountPassword@123',
        ],
    )->assertOk();

    resendDisabledRequest(
        $this,
        $cookies,
        'POST',
        $type.'/email/resend',
    )->assertForbidden();

    $this->assertDatabaseMissing('email_verification_tokens', [
        'user_id' => $user->id,
    ]);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
})->with([
    'customer' => ['customer', 'customer'],
    'staff' => ['staff', 'staff'],
    'admin' => ['staff', 'admin'],
]);
