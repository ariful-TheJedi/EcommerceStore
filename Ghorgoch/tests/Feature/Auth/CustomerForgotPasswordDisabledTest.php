<?php

use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    expect(app()->environment())->toBe('testing');
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');

    config([
        'auth.customer_enabled' => true,
        'auth.email_enabled' => false,
        'session.driver' => 'array',
    ]);

    Mail::fake();
    Notification::fake();
});

it('returns forbidden while email features are disabled', function () {
    $this->postJson('/api/auth/customer/forgot-password', [
        'email' => 'unknown@example.test',
    ])
        ->assertForbidden()
        ->assertJsonStructure(['message', 'data']);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
});

it('does not disclose account eligibility while recovery is disabled', function () {
    User::factory()->create([
        'email' => 'active@example.test',
        'role' => 'customer',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    User::factory()->create([
        'email' => 'disabled@example.test',
        'role' => 'customer',
        'is_active' => false,
    ]);

    User::factory()->unverified()->create([
        'email' => 'unverified@example.test',
        'role' => 'customer',
        'is_active' => true,
    ]);

    User::factory()->create([
        'email' => 'staff@example.test',
        'role' => 'staff',
    ]);

    User::factory()->create([
        'email' => 'admin@example.test',
        'role' => 'admin',
    ]);

    $baseline = $this->postJson(
        '/api/auth/customer/forgot-password',
        ['email' => 'unknown@example.test'],
    );

    $baseline->assertForbidden();

    foreach ([
        'active@example.test',
        'disabled@example.test',
        'unverified@example.test',
        'staff@example.test',
        'admin@example.test',
    ] as $email) {
        $this->postJson('/api/auth/customer/forgot-password', [
            'email' => $email,
        ])
            ->assertForbidden()
            ->assertExactJson($baseline->json());
    }

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
});

it('does not create reset tokens or modify an account', function () {
    $user = User::factory()->create([
        'email' => 'recovery@example.test',
        'role' => 'customer',
        'is_active' => true,
    ]);

    $original = $user->fresh()->getRawOriginal();

    $usersBefore = DB::table('users')->count();
    $customersBefore = DB::table('customers')->count();
    $tokensBefore = DB::table('password_reset_tokens')->count();

    $this->postJson('/api/auth/customer/forgot-password', [
        'email' => $user->email,
    ])->assertForbidden();

    expect($user->fresh()->getRawOriginal())->toBe($original);

    expect(DB::table('users')->count())->toBe($usersBefore);
    expect(DB::table('customers')->count())->toBe($customersBefore);
    expect(DB::table('password_reset_tokens')->count())
        ->toBe($tokensBefore);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
});

it('does not replace an existing reset token while recovery is disabled', function () {
    $user = User::factory()->create([
        'email' => 'existing-token@example.test',
        'role' => 'customer',
    ]);

    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => password_hash(
            str_repeat('a', 64),
            PASSWORD_BCRYPT,
        ),
        'created_at' => now(),
    ]);

    $before = (array) DB::table('password_reset_tokens')
        ->where('email', $user->email)
        ->first();

    $this->postJson('/api/auth/customer/forgot-password', [
        'email' => $user->email,
    ])->assertForbidden();

    $after = (array) DB::table('password_reset_tokens')
        ->where('email', $user->email)
        ->first();

    expect($after)->toBe($before);
});

it('does not establish a login session', function () {
    $user = User::factory()->create([
        'email' => 'no-login@example.test',
        'role' => 'customer',
    ]);

    $this->postJson('/api/auth/customer/forgot-password', [
        'email' => $user->email,
    ])->assertForbidden();

    $this->getJson('/api/auth/customer/me')
        ->assertUnauthorized();

    $this->getJson('/api/auth/staff/me')
        ->assertUnauthorized();
});
