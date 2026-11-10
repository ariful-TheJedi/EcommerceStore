<?php

namespace Tests\Feature\Customers;

use App\Auth\Domain\Services\AuthService;
use App\Customers\Models\Address;
use App\Customers\Models\Customer;
use App\Orders\Domain\Services\OrderCustomerService;
use App\Orders\Models\Order;
use App\Payments\Models\Payment;
use App\Users\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CustomerModuleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function customer(array $fields = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', ...$fields]);

        return Customer::factory()->create(['user_id' => $user->id])->load('user');
    }

    private function login(User $user, string $guard = 'customer'): void
    {
        Auth::forgetGuards();
        $this->actingAs($user, $guard)->withSession([
            'auth_started_at' => now()->timestamp,
            'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
        ]);
    }

    private function staff(array $permissions = ['customers.view', 'customers.manage']): User
    {
        $user = User::factory()->create(['role' => 'staff', 'permissions' => $permissions]);
        $this->login($user, 'staff');

        return $user;
    }

    private function addressPayload(array $overrides = []): array
    {
        return array_replace([
            'recipient_name' => 'Customer Name', 'phone' => '+8801712345678',
            'address_line1' => 'House 12, Road 3', 'city' => 'Dhaka', 'district' => 'Dhaka',
            'division' => 'Dhaka', 'area' => 'Mirpur', 'postal_code' => '1216', 'country_code' => 'BD',
            'delivery_instructions' => 'Call on arrival',
        ], $overrides);
    }

    private function order(Customer $customer, array $overrides = []): Order
    {
        return Order::factory()->create([
            'order_number' => 'TEST-'.fake()->unique()->numerify('##########'),
            'customer_id' => $customer->id, 'shipping_address' => '{"city":"Dhaka"}',
            'subtotal' => 100, 'total_amount' => 100, ...$overrides,
        ]);
    }

    public function test_private_endpoints_require_authentication(): void
    {
        foreach (['/api/customer/profile', '/api/customer/addresses', '/api/customer/orders', '/api/customer/payments', '/api/customers'] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        $this->postJson('/api/customer/addresses', $this->addressPayload())->assertUnauthorized();
        $this->getJson('/api/customers/health')->assertOk()->assertDontSee('email');
    }

    public function test_customer_can_read_and_update_only_permitted_profile_fields(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        $this->login($customer->user);

        $this->getJson('/api/customer/profile')->assertOk()->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonMissingPath('data.customer.password')->assertJsonMissingPath('data.customer.role')
            ->assertJsonMissingPath('data.customer.permissions')->assertHeader('Cache-Control', 'no-store, private');
        $this->patchJson('/api/customer/profile', ['name' => 'Updated Name', 'date_of_birth' => '1995-04-08', 'phone' => '+8801812345678'])
            ->assertOk()->assertJsonPath('data.customer.name', 'Updated Name');
        $this->assertDatabaseHas('users', ['id' => $customer->user_id, 'name' => 'Updated Name', 'phone' => '+8801812345678']);
        $this->assertSame('1995-04-08', $customer->fresh()->date_of_birth->toDateString());
        $this->assertSame($other->user->name, $other->user->fresh()->name);
        $this->patchJson('/api/customer/profile', ['name' => 'Forbidden', 'customer_id' => $other->id, 'role' => 'admin', 'permissions' => ['staff.manage'], 'payment_status' => 'paid', 'email' => 'other@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors(['customer_id', 'role', 'permissions', 'payment_status', 'email']);
        $this->assertDatabaseHas('users', ['id' => $customer->user_id, 'name' => 'Updated Name', 'role' => 'customer']);
        $this->getJson('/api/customers/'.$other->id)->assertUnauthorized();
    }

    public function test_staff_can_create_search_paginate_and_update_customers_without_creating_staff_accounts(): void
    {
        $this->staff();
        $id = $this->postJson('/api/customers', ['name' => 'Unique Customer', 'email' => '  Customer@Example.Test ', 'phone' => '+8801712345678', 'date_of_birth' => '1992-01-01'])
            ->assertCreated()->assertJsonPath('data.customer.email', 'customer@example.test')->json('data.customer.id');
        $this->assertDatabaseHas('users', ['email' => 'customer@example.test', 'role' => 'customer', 'is_active' => true]);
        foreach (['Unique', 'CUSTOMER@EXAMPLE.TEST', '+8801712345678', (string) $id] as $search) {
            $this->getJson('/api/customers?'.http_build_query(['search' => $search, 'per_page' => 1]))->assertOk()
                ->assertJsonCount(1, 'data.customers')->assertJsonPath('data.customers.0.id', $id);
        }
        $this->getJson('/api/customers?per_page=1&page=2')->assertOk()->assertJsonCount(0, 'data.customers');
        $this->patchJson('/api/customers/'.$id, ['name' => 'Staff renamed'])->assertOk()->assertJsonPath('data.customer.name', 'Staff renamed');
        $this->getJson('/api/customers?per_page=101')->assertUnprocessable();
    }

    public function test_staff_permissions_are_required_and_customer_routes_cannot_expose_staff_profiles(): void
    {
        $customer = $this->customer();
        $this->staff([]);
        $this->getJson('/api/customers')->assertForbidden();
        $this->patchJson('/api/customers/'.$customer->id, ['name' => 'No'])->assertForbidden();
        $staff = $this->staff(['customers.view']);
        $this->getJson('/api/customers')->assertOk()->assertJsonCount(1, 'data.customers');
        $this->postJson('/api/customers', ['name' => 'No', 'email' => 'no@example.test'])->assertForbidden();
        $this->postJson('/api/customers/'.$customer->id.'/block', ['reason' => 'No'])->assertForbidden();
        $this->getJson('/api/customer/profile')->assertUnauthorized();
        $this->assertDatabaseHas('users', ['id' => $staff->id, 'role' => 'staff']);
    }

    public function test_identifier_uniqueness_matches_auth_and_validation_is_atomic(): void
    {
        $owner = $this->customer(['email' => 'taken@example.test', 'phone' => '+8801712345678']);
        $target = $this->customer();
        $this->staff();
        $this->postJson('/api/customers', ['name' => 'Duplicate', 'email' => 'TAKEN@EXAMPLE.TEST'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->patchJson('/api/customers/'.$target->id, ['name' => 'Must not persist', 'phone' => '+8801712345678'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertSame($target->user->name, $target->user->fresh()->name);
        $this->patchJson('/api/customers/'.$owner->id, ['email' => 'taken@example.test', 'phone' => '+8801712345678'])->assertOk();
        $staff = User::factory()->create(['role' => 'staff', 'email' => 'staff@example.test']);
        $this->patchJson('/api/customers/'.$target->id, ['email' => 'STAFF@EXAMPLE.TEST'])->assertUnprocessable();
        $this->assertDatabaseHas('users', ['id' => $staff->id, 'role' => 'staff']);
    }

    public function test_first_address_is_default_and_replacing_or_deleting_default_preserves_exactly_one(): void
    {
        $customer = $this->customer();
        $this->login($customer->user);
        $first = $this->postJson('/api/customer/addresses', $this->addressPayload(['is_default' => false]))
            ->assertCreated()->assertJsonPath('data.address.is_default', true)->json('data.address.id');
        $second = $this->postJson('/api/customer/addresses', $this->addressPayload(['label' => 'Office']))
            ->assertCreated()->assertJsonPath('data.address.is_default', false)->json('data.address.id');
        $this->patchJson('/api/customer/addresses/'.$second, ['is_default' => true])->assertOk();
        $this->assertDatabaseHas('addresses', ['id' => $first, 'is_default' => false]);
        $this->assertSame(1, $customer->addresses()->where('is_default', true)->count());
        $this->patchJson('/api/customer/addresses/'.$second, ['is_default' => false])->assertUnprocessable()->assertJsonValidationErrors('is_default');
        $this->deleteJson('/api/customer/addresses/'.$second)->assertOk();
        $this->assertDatabaseHas('addresses', ['id' => $first, 'is_default' => true]);
        $this->getJson('/api/customer/addresses?per_page=1')->assertOk()->assertJsonCount(1, 'data.addresses');
        $this->deleteJson('/api/customer/addresses/'.$first)->assertOk();
        $this->assertSame(0, $customer->addresses()->count());
    }

    public function test_customer_cannot_read_update_delete_or_reassign_another_customers_address(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        $foreign = Address::factory()->create(['customer_id' => $other->id]);
        $this->login($customer->user);
        $this->getJson('/api/customer/addresses/'.$foreign->id)->assertNotFound();
        $this->patchJson('/api/customer/addresses/'.$foreign->id, ['city' => 'Changed'])->assertNotFound();
        $this->deleteJson('/api/customer/addresses/'.$foreign->id)->assertNotFound();
        $this->postJson('/api/customer/addresses', $this->addressPayload(['customer_id' => $other->id]))->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->getJson('/api/customer/addresses')->assertOk()->assertJsonCount(0, 'data.addresses');
        $this->assertDatabaseHas('addresses', ['id' => $foreign->id, 'city' => $foreign->city, 'customer_id' => $other->id]);
    }

    public function test_staff_can_assist_with_addresses_but_ids_are_scoped_to_the_selected_customer(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        $foreign = Address::factory()->create(['customer_id' => $other->id]);
        $this->staff();
        $base = '/api/customers/'.$customer->id.'/addresses';
        $id = $this->postJson($base, $this->addressPayload())->assertCreated()->json('data.address.id');
        $this->getJson($base.'/'.$id)->assertOk();
        $this->patchJson($base.'/'.$id, ['city' => 'Gazipur'])->assertOk()->assertJsonPath('data.address.city', 'Gazipur');
        $this->getJson($base.'/'.$foreign->id)->assertNotFound();
        $this->patchJson($base.'/'.$foreign->id, ['city' => 'Wrong'])->assertNotFound();
        $this->deleteJson($base.'/'.$foreign->id)->assertNotFound();
        $this->deleteJson($base.'/'.$id)->assertOk();
        $this->staff(['customers.view']);
        $this->getJson($base)->assertOk();
        $this->postJson($base, $this->addressPayload())->assertForbidden();
    }

    #[TestWith(['phone', '01712345678'])]
    #[TestWith(['district', ''])]
    #[TestWith(['division', ''])]
    #[TestWith(['postal_code', 'xyz'])]
    #[TestWith(['country_code', 'US'])]
    #[TestWith(['recipient_name', ''])]
    public function test_invalid_delivery_data_is_rejected_without_writing_an_address(string $field, mixed $value): void
    {
        $customer = $this->customer();
        $this->login($customer->user);
        $this->postJson('/api/customer/addresses', $this->addressPayload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame(0, $customer->addresses()->count());
    }

    public function test_database_rejects_multiple_defaults_even_when_the_service_is_bypassed(): void
    {
        $customer = $this->customer();
        Address::factory()->create(['customer_id' => $customer->id, 'is_default' => true]);
        $this->expectException(QueryException::class);
        Address::factory()->create(['customer_id' => $customer->id, 'is_default' => true]);
    }

    public function test_status_changes_record_staff_and_reason_and_revoke_existing_customer_access(): void
    {
        $customer = $this->customer(['password' => 'CorrectPassword@123']);
        $this->login($customer->user);
        $fingerprint = app(AuthService::class)->fingerprint($customer->user);
        $staff = $this->staff();
        $this->postJson('/api/customers/'.$customer->id.'/block', ['reason' => 'Repeated abuse'])->assertOk()->assertJsonPath('data.customer.status', 'blocked');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'blocked_by' => $staff->id, 'blocked_reason' => 'Repeated abuse']);
        $this->assertDatabaseHas('customer_status_history', ['customer_id' => $customer->id, 'actor_id' => $staff->id, 'action' => 'blocked', 'reason' => 'Repeated abuse']);
        $this->getJson('/api/customers?status=blocked')->assertOk()->assertJsonCount(1, 'data.customers');
        $this->login($customer->user->fresh());
        $this->getJson('/api/customer/profile')->assertUnauthorized();
        $this->postJson('/api/auth/customer/login', ['email' => $customer->user->email, 'password' => 'CorrectPassword@123'])->assertUnauthorized();
        $this->login($staff, 'staff');
        $this->postJson('/api/customers/'.$customer->id.'/unblock', ['reason' => 'Reviewed'])->assertOk()->assertJsonPath('data.customer.status', 'active');
        $this->getJson('/api/customers/'.$customer->id.'/status-history')->assertOk()->assertJsonCount(2, 'data.history');
        $this->assertNotSame($fingerprint, app(AuthService::class)->fingerprint($customer->user->fresh()));
        $this->login($customer->user->fresh());
        $this->getJson('/api/customer/profile')->assertOk();
    }

    public function test_notes_require_explicit_permission_and_never_appear_in_customer_profile(): void
    {
        $customer = $this->customer();
        $this->staff();
        $path = '/api/customers/'.$customer->id.'/notes';
        $this->getJson($path)->assertForbidden();
        $this->postJson($path, ['body' => 'Internal only'])->assertForbidden();
        $staff = $this->staff(['customers.view', 'customers.notes']);
        $this->postJson($path, ['body' => 'Internal only'])->assertCreated();
        $this->getJson($path)->assertOk()->assertJsonPath('data.notes.0.body', 'Internal only');
        $this->assertDatabaseHas('customer_notes', ['customer_id' => $customer->id, 'actor_id' => $staff->id]);
        $this->login($customer->user);
        $this->getJson('/api/customer/profile')->assertOk()->assertDontSee('Internal only')->assertJsonMissingPath('data.customer.notes');
        $this->getJson($path)->assertUnauthorized();
    }

    public function test_owned_address_snapshot_survives_address_update_and_deletion(): void
    {
        $customer = $this->customer();
        $address = Address::factory()->create(['customer_id' => $customer->id, 'city' => 'Original City']);
        $this->login($customer->user);
        $order = app(OrderCustomerService::class)->registered($customer->user, $address->id, fn (array $identity): Order => $this->order($customer, $identity));
        $snapshot = $order->shipping_address;
        $this->assertSame('Original City', json_decode($snapshot, true)['city']);
        $this->patchJson('/api/customer/addresses/'.$address->id, ['city' => 'New City'])->assertOk();
        $this->deleteJson('/api/customer/addresses/'.$address->id)->assertOk();
        $this->assertSame($snapshot, $order->fresh()->shipping_address);
    }

    public function test_checkout_rejects_another_customers_address(): void
    {
        $customer = $this->customer();
        $address = Address::factory()->create();
        $this->expectException(ModelNotFoundException::class);
        app(OrderCustomerService::class)->registered($customer->user, $address->id, function (): void {
            $this->fail('Order creation must not run for an address belonging to another customer.');
        });
    }

    public function test_guest_order_matching_registered_contact_is_unlinked_and_creates_no_account(): void
    {
        $customer = $this->customer(['email' => 'existing@example.test', 'phone' => '+8801712345678']);
        $order = app(OrderCustomerService::class)->guest([
            'name' => 'Guest', 'email' => 'EXISTING@example.test', 'phone' => '+8801712345678',
        ], $this->addressPayload(), fn (array $identity): Order => $this->order($customer, $identity));
        $this->assertNull($order->customer_id);
        $this->assertSame('existing@example.test', $order->guest_email);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('customers', 1);
        $this->getJson('/api/customer/profile')->assertUnauthorized();
        $this->login($customer->user);
        $this->getJson('/api/customer/orders')->assertOk()->assertJsonCount(0, 'data.orders');
    }

    public function test_blocked_customer_cannot_create_an_order_even_when_bypassing_http_auth(): void
    {
        $customer = $this->customer(['is_active' => false]);
        $this->expectException(ValidationException::class);
        $this->order($customer);
    }

    public function test_registered_order_boundary_rechecks_block_status_before_invoking_checkout(): void
    {
        $customer = $this->customer();
        $address = Address::factory()->create(['customer_id' => $customer->id]);
        $customer->user->is_active = false;
        $customer->user->save();
        $this->expectException(ValidationException::class);
        app(OrderCustomerService::class)->registered($customer->user, $address->id, function (): void {
            $this->fail('A blocked customer cannot reach order creation.');
        });
    }

    public function test_order_and_payment_summaries_are_scoped_and_staff_needs_domain_permissions(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        $order = $this->order($customer);
        $this->order($other);
        Payment::factory()->create(['order_id' => $order->id, 'provider' => 'cash', 'amount' => 100, 'provider_response' => '{"secret":"private"}']);
        $this->login($customer->user);
        $this->getJson('/api/customer/orders')->assertOk()->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.id', $order->id)->assertJsonMissingPath('data.orders.0.shipping_address');
        $this->getJson('/api/customer/payments')->assertOk()->assertJsonCount(1, 'data.payments')->assertDontSee('private')->assertJsonMissingPath('data.payments.0.provider_response');
        $this->staff();
        $this->getJson('/api/customers/'.$customer->id.'/orders')->assertForbidden();
        $this->getJson('/api/customers/'.$customer->id.'/payments')->assertForbidden();
        $this->staff(['customers.view', 'orders.view', 'payments.view']);
        $this->getJson('/api/customers/'.$customer->id.'/orders')->assertOk()->assertJsonCount(1, 'data.orders');
        $this->getJson('/api/customers/'.$customer->id.'/payments')->assertOk()->assertJsonCount(1, 'data.payments');
    }

    public function test_account_closure_anonymizes_profile_and_addresses_but_retains_transactions(): void
    {
        $customer = $this->customer(['email' => 'close@example.test', 'password' => 'CorrectPassword@123']);
        $order = $this->order($customer);
        Payment::factory()->create(['order_id' => $order->id, 'provider' => 'cash', 'amount' => 100]);
        Address::factory()->create(['customer_id' => $customer->id]);
        $this->login($customer->user);
        $this->postJson('/api/customer/close', ['current_password' => 'wrong'])->assertUnprocessable();
        $this->assertDatabaseHas('users', ['id' => $customer->user_id, 'is_active' => true]);
        $this->postJson('/api/customer/close', ['current_password' => 'CorrectPassword@123'])->assertOk();
        $this->assertSame('Closed customer', $customer->user->fresh()->name);
        $this->assertFalse($customer->user->fresh()->is_active);
        $this->assertNull($customer->user->fresh()->phone);
        $this->assertNotSame('close@example.test', $customer->user->fresh()->email);
        $this->assertNotNull($customer->fresh()->closed_at);
        $this->assertSame(0, $customer->addresses()->count());
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'shipping_address' => $order->shipping_address]);
        $this->assertDatabaseCount('payments', 1);
        $this->getJson('/api/customer/profile')->assertUnauthorized();
        $this->staff();
        $this->postJson('/api/customers/'.$customer->id.'/unblock', ['reason' => 'Cannot reopen'])->assertConflict();
    }

    public function test_customer_writes_require_csrf_outside_the_test_environment(): void
    {
        $customer = $this->customer();
        $this->login($customer->user);
        $this->app->instance('env', 'local');
        $this->patchJson('/api/customer/profile', ['name' => 'Forged'])->assertStatus(419);
        $this->postJson('/api/customer/addresses', $this->addressPayload())->assertStatus(419);
        $this->assertSame($customer->user->name, $customer->user->fresh()->name);
    }

    public function test_staff_cannot_inject_authority_or_reopen_an_anonymized_account(): void
    {
        $customer = $this->customer();
        $this->staff();
        $this->patchJson('/api/customers/'.$customer->id, ['role' => 'staff', 'permissions' => ['staff.manage'], 'is_active' => false, 'payment_status' => 'paid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['role', 'permissions', 'is_active', 'payment_status']);
        $this->postJson('/api/customers/'.$customer->id.'/block', ['reason' => '   '])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/api/customers/'.$customer->id.'/close', ['reason' => 'Requested closure'])->assertOk();
        $this->patchJson('/api/customers/'.$customer->id, ['name' => 'Reopened'])->assertConflict();
        $this->postJson('/api/customers/'.$customer->id.'/addresses', $this->addressPayload())->assertConflict();
        $this->getJson('/api/customers?status=closed')->assertOk()->assertJsonCount(1, 'data.customers');
        $this->assertDatabaseHas('customer_status_history', ['customer_id' => $customer->id, 'action' => 'closed']);
    }

    public function test_order_boundary_rolls_back_failed_checkout_and_prevents_authenticated_guest_bypass(): void
    {
        $customer = $this->customer();
        $address = Address::factory()->create(['customer_id' => $customer->id]);
        try {
            app(OrderCustomerService::class)->registered($customer->user, $address->id, function (array $identity) use ($customer): void {
                $this->order($customer, $identity);
                throw new \RuntimeException('Checkout failed');
            });
            $this->fail('Checkout should throw.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Checkout failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('orders', 0);
        $this->login($customer->user);
        $this->expectException(HttpException::class);
        app(OrderCustomerService::class)->guest(['name' => 'Guest', 'phone' => '+8801712345678'], $this->addressPayload(), function (): void {
            $this->fail('Signed-in customers must use the registered checkout boundary.');
        });
    }

    public function test_guest_contact_cannot_supply_an_account_identifier(): void
    {
        $this->expectException(ValidationException::class);
        app(OrderCustomerService::class)->guest(['name' => 'Guest', 'phone' => '+8801712345678', 'customer_id' => 1], $this->addressPayload(), function (): void {
            $this->fail('An unverified guest cannot choose an account.');
        });
    }

    public function test_staff_email_change_clears_verification_and_revokes_customer_sessions(): void
    {
        $customer = $this->customer(['email_verified_at' => now()]);
        $oldFingerprint = app(AuthService::class)->fingerprint($customer->user);
        $this->staff();
        $this->patchJson('/api/customers/'.$customer->id, ['email' => 'NEW@example.test'])
            ->assertOk()->assertJsonPath('data.customer.email', 'new@example.test')->assertJsonPath('data.customer.email_verified', false);
        $this->assertNotSame($oldFingerprint, app(AuthService::class)->fingerprint($customer->user->fresh()));
    }
}
