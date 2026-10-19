<?php

namespace Tests\Feature\Users;

use App\Auth\Domain\Services\AuthService;
use App\Customers\Models\Customer;
use App\Orders\Models\Order;
use App\Payments\Models\Payment;
use App\Users\Domain\Services\StaffService;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class StaffIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function login(User $user): void
    {
        $this->actingAs($user, 'staff')->withSession([
            'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
            'auth_started_at' => now()->timestamp,
        ]);
    }

    private function endpoint(string $path, string $permission): void
    {
        Route::middleware('api')->post($path, fn () => response()->json(['ok' => true]))
            ->defaults('staff_permission', $permission);
    }

    #[TestWith(['products', 'catalog.view'])]
    #[TestWith(['categories', 'catalog.manage'])]
    #[TestWith(['inventory', 'inventory.adjust'])]
    #[TestWith(['customers', 'customers.view'])]
    #[TestWith(['customers', 'customers.manage'])]
    #[TestWith(['orders', 'orders.view'])]
    #[TestWith(['orders', 'orders.process'])]
    #[TestWith(['payments', 'payments.record'])]
    #[TestWith(['payments', 'payments.reconcile'])]
    public function test_domain_endpoints_require_their_declared_permission(string $module, string $permission): void
    {
        $path = '/api/'.$module.'/permission-contract';
        $this->endpoint($path, $permission);
        $staff = User::factory()->create(['role' => 'staff']);
        $this->login($staff);

        $this->postJson($path)->assertForbidden();

        $staff->permissions = [$permission];
        $staff->save();
        $this->login($staff->fresh());
        $this->postJson($path)->assertOk()->assertJsonPath('ok', true);
    }

    public function test_domain_endpoints_require_a_staff_session(): void
    {
        $this->endpoint('/api/orders/permission-contract', 'orders.view');

        $this->postJson('/api/orders/permission-contract')->assertUnauthorized();

        $customer = User::factory()->create(['role' => 'customer']);
        $this->login($customer);
        $this->postJson('/api/orders/permission-contract')->assertUnauthorized();
    }

    public function test_new_endpoints_without_permission_metadata_deny_even_administrators(): void
    {
        Route::middleware('api')->get('/api/products/undeclared', fn () => ['secret' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->login($admin);

        $this->getJson('/api/products/undeclared')->assertForbidden();

        $this->getJson('/api/products/undeclared?staff_permission=catalog.view')->assertForbidden();
    }

    public function test_disabled_staff_cannot_access_a_domain_endpoint(): void
    {
        $this->endpoint('/api/orders/permission-contract', 'orders.view');
        $staff = User::factory()->create(['role' => 'staff', 'permissions' => ['orders.view']]);
        $this->login($staff);
        $staff->is_active = false;
        $staff->save();

        $this->postJson('/api/orders/permission-contract')->assertUnauthorized();
    }

    public function test_public_health_checks_remain_public(): void
    {
        foreach (['products', 'categories', 'inventory', 'customers', 'orders', 'payments'] as $module) {
            $this->getJson('/api/'.$module.'/health')->assertOk()->assertJsonPath('module', $module);
        }
    }

    public function test_reactivation_does_not_restore_an_old_session(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $this->login($staff);
        $service = app(StaffService::class);

        $service->update($admin, $staff->id, ['is_active' => false]);
        $service->update($admin, $staff->id, ['is_active' => true]);

        $this->getJson('/api/users/me')->assertUnauthorized();
        $this->assertDatabaseHas('users', ['id' => $staff->id, 'is_active' => true]);
    }

    public function test_restoring_a_previous_permission_set_does_not_restore_old_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff', 'permissions' => ['orders.view']]);
        $this->login($staff);
        $service = app(StaffService::class);

        $service->update($admin, $staff->id, ['permissions' => []]);
        $service->update($admin, $staff->id, ['permissions' => ['orders.view']]);

        $this->getJson('/api/users/me')->assertUnauthorized();
    }

    public function test_order_processing_and_payment_recording_use_the_authenticated_staff(): void
    {
        $order = $this->order();
        $staff = User::factory()->create(['role' => 'staff', 'permissions' => ['orders.process', 'payments.record']]);
        $other = User::factory()->create(['role' => 'staff']);
        $this->login($staff);

        $order->status = 'processing';
        $order->processed_by = $other->id;
        $order->save();
        $payment = Payment::factory()->create([
            'order_id' => $order->id, 'provider' => 'cash', 'amount' => 100, 'recorded_by' => $other->id,
        ]);

        $this->assertSame($staff->id, $order->fresh()->processedBy->id);
        $this->assertSame($staff->id, $payment->fresh()->recordedBy->id);
        $this->assertNull($payment->fresh()->reconciled_by);
    }

    public function test_reconciliation_preserves_the_original_recorder_and_identifies_the_reconciler(): void
    {
        $order = $this->order();
        $recorder = User::factory()->create(['role' => 'staff']);
        $payment = Payment::factory()->create([
            'order_id' => $order->id, 'provider' => 'cash', 'amount' => 100, 'recorded_by' => $recorder->id,
        ]);
        $reconciler = User::factory()->create(['role' => 'staff', 'permissions' => ['payments.reconcile']]);
        $this->login($reconciler);

        $payment->status = 'successful';
        $payment->recorded_by = $reconciler->id;
        $payment->save();

        $this->assertSame($recorder->id, $payment->fresh()->recordedBy->id);
        $this->assertSame($reconciler->id, $payment->fresh()->reconciledBy->id);
    }

    public function test_recording_permission_does_not_allow_reconciliation(): void
    {
        $order = $this->order();
        $payment = Payment::factory()->create(['order_id' => $order->id, 'provider' => 'cash', 'amount' => 100]);
        $staff = User::factory()->create(['role' => 'staff', 'permissions' => ['payments.record']]);
        $this->login($staff);
        Route::middleware('api')->post('/api/payments/reconcile-contract', function () use ($payment) {
            $payment->status = 'successful';
            $payment->save();

            return response()->json(['ok' => true]);
        })->defaults('staff_permission', 'payments.record');

        $this->postJson('/api/payments/reconcile-contract')->assertForbidden();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending', 'reconciled_by' => null]);
    }

    public function test_staff_password_changes_are_audited_without_secrets(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->login($staff);

        $this->postJson('/api/auth/staff/password', [
            'current_password' => 'password',
            'password' => 'NewStaffPassword@123',
            'password_confirmation' => 'NewStaffPassword@123',
        ])->assertOk();

        $this->assertDatabaseHas('staff_activity', ['staff_id' => $staff->id, 'actor_id' => $staff->id, 'action' => 'password_changed']);
        $this->assertStringNotContainsString('NewStaffPassword', DB::table('staff_activity')->value('after'));
        $this->assertStringNotContainsString('password', DB::table('staff_activity')->value('after'));
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_limited_managers_cannot_take_over_or_reactivate_more_privileged_staff(bool $active): void
    {
        $manager = User::factory()->create(['role' => 'staff', 'permissions' => ['staff.manage']]);
        $target = User::factory()->create([
            'role' => 'staff', 'permissions' => ['payments.reconcile'], 'is_active' => $active,
        ]);
        $this->login($manager);

        $this->patchJson('/api/users/'.$target->id, ['email' => 'takeover@example.test'])->assertForbidden();
        $this->postJson('/api/users/'.$target->id.'/reactivate')->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'email' => $target->email, 'is_active' => $active]);
        $this->assertDatabaseCount('staff_activity', 0);
    }

    private function order(): Order
    {
        return Order::factory()->create([
            'order_number' => 'STAFF-INTEGRATION-001', 'customer_id' => Customer::factory(),
            'shipping_address' => '{}', 'subtotal' => 100, 'total_amount' => 100,
        ]);
    }
}
