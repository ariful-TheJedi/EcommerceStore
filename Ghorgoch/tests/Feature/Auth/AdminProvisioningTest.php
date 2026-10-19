<?php

namespace Tests\Feature\Auth;

use App\Auth\Domain\Services\AuthService;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminProvisioningTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.customer_enabled' => true, 'auth.email_enabled' => false]);
    }

    public function test_email_features_return_403_without_sending_mail(): void
    {
        Notification::fake();
        foreach (['customer', 'staff'] as $type) {
            foreach (['forgot-password', 'reset-password'] as $path) {
                $this->postJson('/api/auth/'.$type.'/'.$path)->assertForbidden();
            }
        }
        $this->assertDatabaseCount('users', 0);
        Notification::assertNothingSent();
    }

    public function test_unauthenticated_user_creation_returns_401(): void
    {
        $this->postJson('/api/auth/admin/users')->assertUnauthorized();
    }

    #[DataProvider('roles')]
    public function test_only_admin_can_create_users(string $role, int $status): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => $role]);
        $this->authenticate($user);
        $this->postJson('/api/auth/admin/users', $this->payload())->assertStatus($status);
        if ($status === 201) {
            $created = User::where('email', 'new@example.test')->firstOrFail();
            $this->assertTrue(Hash::check('StrongPassword@123', $created->password));
            $this->assertSame('customer', $created->role);
            $this->assertDatabaseHas('customers', ['user_id' => $created->id]);
            $this->postJson('/api/auth/customer/login', ['email' => $created->email, 'password' => 'StrongPassword@123'])->assertOk();
        } else {
            $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
        }
        Notification::assertNothingSent();
    }

    public static function roles(): array
    {
        return [['admin', 201], ['staff', 403], ['customer', 401]];
    }

    public function test_admin_creation_validates_fields_and_duplicate_emails(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->authenticate($user);
        $this->postJson('/api/auth/admin/users')->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password', 'role']);
        $this->postJson('/api/auth/admin/users', [...$this->payload(), 'role' => 'superadmin'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postJson('/api/auth/admin/users', [...$this->payload(), 'email' => $user->email])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_authenticated_email_features_are_paused_without_creating_tokens(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['role' => 'admin']);
        $this->authenticate($user);
        foreach (['email', 'email/verify', 'email/resend'] as $path) {
            $this->postJson('/api/auth/staff/'.$path)->assertForbidden();
        }
        $this->assertFalse(app(AuthService::class)->sendVerification($user));
        app(AuthService::class)->forgotPassword('staff', $user->email);
        $this->assertDatabaseCount('email_verification_tokens', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNothingSent();
    }

    public function test_bootstrap_creates_hashed_admin_and_refuses_overwrite(): void
    {
        Notification::fake();
        config(['auth.bootstrap_admin' => ['name' => 'Super Admin', 'email' => ' SuperAdmin@Example.Test ', 'password' => 'TemporaryPass@123']]);
        $this->artisan('auth:bootstrap-admin')->assertExitCode(0);
        $admin = User::where('email', 'superadmin@example.test')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check('TemporaryPass@123', $admin->password));
        config(['auth.bootstrap_admin.password' => 'AnotherPassword@123']);
        $this->artisan('auth:bootstrap-admin')->assertExitCode(1);
        $this->assertTrue(Hash::check('TemporaryPass@123', $admin->fresh()->password));
        $this->assertDatabaseCount('users', 1);
        $this->postJson('/api/auth/staff/login', ['email' => $admin->email, 'password' => 'TemporaryPass@123'])->assertOk();
        Notification::assertNothingSent();
    }

    public function test_bootstrap_rejects_missing_credentials(): void
    {
        config(['auth.bootstrap_admin' => ['name' => 'Admin', 'email' => null, 'password' => null]]);
        $this->artisan('auth:bootstrap-admin')->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
    }

    private function authenticate(User $user): void
    {
        $this->actingAs($user, 'staff')->withSession([
            'auth_started_at' => now()->timestamp,
            'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
        ]);
    }

    private function payload(): array
    {
        return ['name' => 'New User', 'email' => 'new@example.test', 'role' => 'customer',
            'password' => 'StrongPassword@123', 'password_confirmation' => 'StrongPassword@123'];
    }
}
