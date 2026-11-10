<?php

declare(strict_types=1);

use App\Auth\Domain\Services\AuthService;
use App\Customers\Models\Address;
use App\Customers\Models\Customer;
use App\Users\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

function customerDefaultAccount(): Customer
{
    $user = User::factory()->create(['role' => 'customer']);

    return Customer::factory()
        ->create(['user_id' => $user->id])
        ->load('user');
}

function customerDefaultBase($test, Customer $customer, string $actor): string
{
    $user = $customer->user;
    $guard = 'customer';
    $base = '/api/customer/addresses';

    if ($actor === 'staff') {
        $user = User::factory()->create([
            'role' => 'staff',
            'permissions' => ['customers.view', 'customers.manage'],
        ]);

        $guard = 'staff';
        $base = '/api/customers/'.$customer->id.'/addresses';
    }

    Auth::forgetGuards();

    $test->actingAs($user, $guard)->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
    ]);

    return $base;
}

function customerDefaultPayload(array $overrides = []): array
{
    return array_replace([
        'recipient_name' => 'Default Address Customer',
        'phone' => '+8801712345678',
        'address_line1' => 'House 12, Road 3',
        'city' => 'Dhaka',
        'district' => 'Dhaka',
        'division' => 'Dhaka',
    ], $overrides);
}

function customerDefaultAssertOnly(Customer $customer, int $addressId): void
{
    $ids = $customer->addresses()
        ->where('is_default', true)
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();

    expect($ids)->toBe([$addressId]);
}

dataset('customers.default.actors', [
    'customer self-service' => ['customer'],
    'staff administration' => ['staff'],
]);

it('makes the first address default even when false is explicitly supplied', function (
    string $actor,
) {
    $customer = customerDefaultAccount();
    $base = customerDefaultBase($this, $customer, $actor);

    $id = $this->postJson($base, customerDefaultPayload([
        'is_default' => false,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.address.is_default', true)
        ->json('data.address.id');

    customerDefaultAssertOnly($customer, (int) $id);

    $this->getJson($base.'/'.$id)
        ->assertOk()
        ->assertJsonPath('data.address.is_default', true);
})->with('customers.default.actors');

it('preserves the existing default when a later address omits the default flag', function (
    string $actor,
) {
    $customer = customerDefaultAccount();
    $base = customerDefaultBase($this, $customer, $actor);

    $first = $this->postJson($base, customerDefaultPayload())
        ->assertCreated()
        ->json('data.address.id');

    $second = $this->postJson($base, customerDefaultPayload([
        'label' => 'Office',
    ]))
        ->assertCreated()
        ->assertJsonPath('data.address.is_default', false)
        ->json('data.address.id');

    customerDefaultAssertOnly($customer, (int) $first);

    $this->assertDatabaseHas('addresses', [
        'id' => $second,
        'customer_id' => $customer->id,
        'is_default' => false,
    ]);
})->with('customers.default.actors');

it('replaces the default when a new address explicitly requests it', function (
    string $actor,
) {
    $customer = customerDefaultAccount();
    $base = customerDefaultBase($this, $customer, $actor);

    $first = $this->postJson($base, customerDefaultPayload())
        ->assertCreated()
        ->json('data.address.id');

    $second = $this->postJson($base, customerDefaultPayload([
        'label' => 'New Default',
        'is_default' => true,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.address.is_default', true)
        ->json('data.address.id');

    customerDefaultAssertOnly($customer, (int) $second);

    $this->assertDatabaseHas('addresses', [
        'id' => $first,
        'is_default' => false,
    ]);
})->with('customers.default.actors');

it('switches the default through updates and tolerates selecting it again', function (
    string $actor,
) {
    $customer = customerDefaultAccount();

    $first = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => true,
    ]);

    $second = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => false,
    ]);

    $base = customerDefaultBase($this, $customer, $actor);

    foreach ([$second->id, $second->id, $first->id] as $id) {
        $this->patchJson($base.'/'.$id, ['is_default' => true])
            ->assertOk()
            ->assertJsonPath('data.address.is_default', true);

        customerDefaultAssertOnly($customer, (int) $id);
    }
})->with('customers.default.actors');

it('rejects clearing the current default without persisting other changes', function (
    string $actor,
) {
    $customer = customerDefaultAccount();

    $default = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => true,
    ]);

    Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => false,
    ]);

    $before = $default->fresh()->getRawOriginal();

    $base = customerDefaultBase($this, $customer, $actor);

    $this->patchJson($base.'/'.$default->id, [
        'is_default' => false,
        'city' => 'Must Not Persist',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_default');

    expect($default->fresh()->getRawOriginal())->toBe($before);

    customerDefaultAssertOnly($customer, (int) $default->id);
})->with('customers.default.actors');

it('preserves the default when updating or deleting a nondefault address', function (
    string $actor,
) {
    $customer = customerDefaultAccount();

    $default = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => true,
    ]);

    $other = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => false,
    ]);

    $base = customerDefaultBase($this, $customer, $actor);

    $this->patchJson($base.'/'.$other->id, [
        'city' => 'Gazipur',
        'is_default' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.address.is_default', false);

    customerDefaultAssertOnly($customer, (int) $default->id);

    $this->deleteJson($base.'/'.$other->id)->assertOk();

    customerDefaultAssertOnly($customer, (int) $default->id);

    expect($customer->addresses()->count())->toBe(1);
})->with('customers.default.actors');

it('promotes the oldest remaining address when deleting the default', function (
    string $actor,
) {
    $customer = customerDefaultAccount();

    $oldest = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => false,
        'created_at' => now()->subDays(3),
    ]);

    $newer = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => false,
        'created_at' => now()->subDays(2),
    ]);

    $default = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => true,
        'created_at' => now()->subDay(),
    ]);

    $base = customerDefaultBase($this, $customer, $actor);

    $this->deleteJson($base.'/'.$default->id)->assertOk();

    customerDefaultAssertOnly($customer, (int) $oldest->id);

    $this->getJson($base.'/'.$oldest->id)
        ->assertOk()
        ->assertJsonPath('data.address.is_default', true);

    $this->deleteJson($base.'/'.$oldest->id)->assertOk();

    customerDefaultAssertOnly($customer, (int) $newer->id);

    $this->deleteJson($base.'/'.$newer->id)->assertOk();

    expect($customer->addresses()->count())->toBe(0);
    expect($customer->addresses()->where('is_default', true)->count())->toBe(0);

    // Creating an address after deleting all addresses starts the policy again.
    $replacement = $this->postJson($base, customerDefaultPayload([
        'is_default' => false,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.address.is_default', true)
        ->json('data.address.id');

    customerDefaultAssertOnly($customer, (int) $replacement);
})->with('customers.default.actors');

it('keeps another customers default unchanged during switching and deletion', function (
    string $actor,
) {
    $customer = customerDefaultAccount();
    $other = customerDefaultAccount();

    $ownDefault = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => true,
    ]);

    $ownSecond = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => false,
    ]);

    $foreignDefault = Address::factory()->create([
        'customer_id' => $other->id,
        'is_default' => true,
    ]);

    $foreignBefore = $foreignDefault->fresh()->getRawOriginal();

    $base = customerDefaultBase($this, $customer, $actor);

    $this->patchJson($base.'/'.$ownSecond->id, [
        'is_default' => true,
    ])->assertOk();

    customerDefaultAssertOnly($customer, (int) $ownSecond->id);
    customerDefaultAssertOnly($other, (int) $foreignDefault->id);

    $this->patchJson($base.'/'.$foreignDefault->id, [
        'is_default' => true,
    ])->assertNotFound();

    $this->deleteJson($base.'/'.$ownSecond->id)->assertOk();

    customerDefaultAssertOnly($customer, (int) $ownDefault->id);
    customerDefaultAssertOnly($other, (int) $foreignDefault->id);

    expect($foreignDefault->fresh()->getRawOriginal())->toBe($foreignBefore);
})->with('customers.default.actors');

it('does not replace the current default when a new default address fails validation', function (
    string $actor,
) {
    $customer = customerDefaultAccount();

    $default = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => true,
    ]);

    $base = customerDefaultBase($this, $customer, $actor);

    $this->postJson($base, customerDefaultPayload([
        'is_default' => true,
        'phone' => 'invalid',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');

    expect($customer->addresses()->count())->toBe(1);

    customerDefaultAssertOnly($customer, (int) $default->id);
})->with('customers.default.actors');

it('enforces one default per customer at database level while allowing separate customer defaults', function () {
    $customer = customerDefaultAccount();
    $other = customerDefaultAccount();

    $first = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => true,
    ]);

    $foreign = Address::factory()->create([
        'customer_id' => $other->id,
        'is_default' => true,
    ]);

    $second = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => false,
    ]);

    $constraintFailure = null;

    try {
        // A nested transaction lets PostgreSQL recover from the constraint error.
        DB::transaction(function () use ($second): void {
            DB::table('addresses')
                ->where('id', $second->id)
                ->update(['is_default' => true]);
        });
    } catch (QueryException $exception) {
        $constraintFailure = $exception;
    }

    expect($constraintFailure)->toBeInstanceOf(QueryException::class);

    expect((string) $constraintFailure->getCode())
        ->toBeIn(['23505', '23000']);

    customerDefaultAssertOnly($customer, (int) $first->id);
    customerDefaultAssertOnly($other, (int) $foreign->id);

    $this->assertDatabaseHas('addresses', [
        'id' => $second->id,
        'is_default' => false,
    ]);
});
