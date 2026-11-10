<?php

declare(strict_types=1);

use App\Auth\Domain\Services\AuthService;
use App\Customers\Models\Address;
use App\Customers\Models\Customer;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(LazilyRefreshDatabase::class);

function customerAddressAccount(): Customer
{
    $user = User::factory()->create(['role' => 'customer']);

    return Customer::factory()
        ->create(['user_id' => $user->id])
        ->load('user');
}

function customerAddressLogin(
    $test,
    User $user,
    string $guard = 'customer',
): void {
    Auth::forgetGuards();

    $test->actingAs($user, $guard)->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
    ]);
}

function customerAddressStaff($test): User
{
    $staff = User::factory()->create([
        'role' => 'staff',
        'permissions' => ['customers.view', 'customers.manage'],
    ]);

    customerAddressLogin($test, $staff, 'staff');

    return $staff;
}

function customerAddressPayload(array $overrides = []): array
{
    return array_replace([
        'recipient_name' => 'Rahim Customer',
        'phone' => '+8801712345678',
        'address_line1' => 'House 12, Road 3',
        'address_line2' => 'Second floor',
        'city' => 'Dhaka',
        'district' => 'Dhaka',
        'division' => 'Dhaka',
        'area' => 'Mirpur',
        'upazila' => 'Mirpur',
        'postal_code' => '1216',
        'country_code' => 'BD',
        'label' => 'Home',
        'delivery_instructions' => 'Call on arrival',
    ], $overrides);
}

it('allows a customer to create read update and delete an owned address', function () {
    $customer = customerAddressAccount();

    customerAddressLogin($this, $customer->user);

    $payload = customerAddressPayload();

    $created = $this->postJson('/api/customer/addresses', $payload)
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private');

    $id = $created->json('data.address.id');

    expect($id)->not->toBeNull();

    foreach ($payload as $field => $value) {
        $created->assertJsonPath('data.address.'.$field, $value);
    }

    $this->assertDatabaseHas('addresses', [
        'id' => $id,
        'customer_id' => $customer->id,
        ...$payload,
    ]);

    $this->getJson('/api/customer/addresses/'.$id)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.address.id', $id)
        ->assertJsonPath('data.address.address_line1', $payload['address_line1']);

    $this->patchJson('/api/customer/addresses/'.$id, [
        'recipient_name' => 'Updated Recipient',
        'city' => 'Gazipur',
        'delivery_instructions' => 'Call before delivery',
    ])
        ->assertOk()
        ->assertJsonPath('data.address.recipient_name', 'Updated Recipient')
        ->assertJsonPath('data.address.city', 'Gazipur')
        ->assertJsonPath(
            'data.address.delivery_instructions',
            'Call before delivery',
        )
        ->assertJsonPath('data.address.phone', $payload['phone'])
        ->assertJsonPath('data.address.address_line1', $payload['address_line1']);

    $this->assertDatabaseHas('addresses', [
        'id' => $id,
        'customer_id' => $customer->id,
        'recipient_name' => 'Updated Recipient',
        'city' => 'Gazipur',
    ]);

    $this->deleteJson('/api/customer/addresses/'.$id)->assertOk();

    expect($customer->addresses()->whereKey($id)->exists())->toBeFalse();

    $this->getJson('/api/customer/addresses/'.$id)->assertNotFound();

    $this->getJson('/api/customer/addresses')
        ->assertOk()
        ->assertJsonCount(0, 'data.addresses')
        ->assertJsonPath('data.pagination.total', 0);
});

it('accepts only required address fields and defaults the country to Bangladesh', function () {
    $customer = customerAddressAccount();

    customerAddressLogin($this, $customer->user);

    $payload = [
        'recipient_name' => 'Minimal Recipient',
        'phone' => '+8801712345678',
        'address_line1' => 'House 10',
        'city' => 'Dhaka',
        'district' => 'Dhaka',
        'division' => 'Dhaka',
    ];

    $response = $this->postJson('/api/customer/addresses', $payload)
        ->assertCreated()
        ->assertJsonPath('data.address.country_code', 'BD');

    $this->assertDatabaseHas('addresses', [
        'id' => $response->json('data.address.id'),
        'customer_id' => $customer->id,
        'country_code' => 'BD',
        ...$payload,
    ]);
});

it('allows address phones to match profile phones and other saved addresses', function () {
    $customer = customerAddressAccount();
    $phone = $customer->user->phone ?? '+8801712345678';

    customerAddressLogin($this, $customer->user);

    foreach (['Home', 'Office'] as $label) {
        $this->postJson('/api/customer/addresses', customerAddressPayload([
            'phone' => $phone,
            'label' => $label,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.address.phone', $phone);
    }

    expect($customer->addresses()->where('phone', $phone)->count())->toBe(2);
});

it('paginates only addresses owned by the authenticated customer', function () {
    $customer = customerAddressAccount();
    $other = customerAddressAccount();

    $ownedIds = [];

    foreach (range(1, 3) as $number) {
        $ownedIds[] = Address::factory()->create([
            'customer_id' => $customer->id,
            'is_default' => $number === 1,
        ])->id;
    }

    Address::factory()->create([
        'customer_id' => $other->id,
        'recipient_name' => 'Private Foreign Recipient',
        'is_default' => true,
    ]);

    customerAddressLogin($this, $customer->user);

    $this->getJson('/api/customer/addresses')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonCount(3, 'data.addresses')
        ->assertJsonPath('data.pagination.per_page', 15)
        ->assertJsonPath('data.pagination.total', 3)
        ->assertDontSee('Private Foreign Recipient');

    $seen = [];

    foreach ([1 => 2, 2 => 1] as $page => $count) {
        $response = $this->getJson(
            '/api/customer/addresses?per_page=2&page='.$page,
        )
            ->assertOk()
            ->assertJsonCount($count, 'data.addresses')
            ->assertJsonPath('data.pagination.current_page', $page)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.last_page', 2);

        $seen = [
            ...$seen,
            ...array_column($response->json('data.addresses'), 'id'),
        ];
    }

    sort($seen);
    sort($ownedIds);

    expect($seen)->toBe($ownedIds);

    $this->getJson('/api/customer/addresses?per_page=2&page=3')
        ->assertOk()
        ->assertJsonCount(0, 'data.addresses')
        ->assertJsonPath('data.pagination.total', 3);
});

it('prevents customers from reading changing or deleting foreign addresses', function () {
    $customer = customerAddressAccount();
    $other = customerAddressAccount();

    $foreign = Address::factory()->create([
        'customer_id' => $other->id,
        'is_default' => true,
    ]);

    $before = $foreign->fresh()->getRawOriginal();

    customerAddressLogin($this, $customer->user);

    $path = '/api/customer/addresses/'.$foreign->id;

    $this->getJson($path)->assertNotFound();
    $this->patchJson($path, ['city' => 'Unauthorized City'])->assertNotFound();
    $this->deleteJson($path)->assertNotFound();

    expect($foreign->fresh()->getRawOriginal())->toBe($before);
    expect($customer->addresses()->count())->toBe(0);
});

it('rejects ownership injection and unexpected fields without writing changes', function () {
    $customer = customerAddressAccount();
    $other = customerAddressAccount();

    $address = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => true,
    ]);

    $before = $address->fresh()->getRawOriginal();

    customerAddressLogin($this, $customer->user);

    $forbidden = [
        'customer_id' => $other->id,
        'unexpected_field' => 'unexpected',
    ];

    $this->postJson('/api/customer/addresses', customerAddressPayload(
        $forbidden,
    ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(array_keys($forbidden));

    $this->patchJson('/api/customer/addresses/'.$address->id, [
        'city' => 'Must Not Persist',
        ...$forbidden,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(array_keys($forbidden));

    expect($customer->addresses()->count())->toBe(1);
    expect($address->fresh()->getRawOriginal())->toBe($before);
});

it('requires all mandatory delivery fields when creating an address', function () {
    $customer = customerAddressAccount();

    customerAddressLogin($this, $customer->user);

    $this->postJson('/api/customer/addresses', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'recipient_name',
            'phone',
            'address_line1',
            'city',
            'district',
            'division',
        ]);

    expect($customer->addresses()->count())->toBe(0);
});

dataset('customers.address.invalid_fields', [
    'blank recipient' => ['recipient_name', '   '],
    'local phone' => ['phone', '01712345678'],
    'invalid mobile prefix' => ['phone', '+8801212345678'],
    'short phone' => ['phone', '+880171234567'],
    'blank address' => ['address_line1', ''],
    'blank city' => ['city', ''],
    'blank district' => ['district', ''],
    'blank division' => ['division', ''],
    'short postal code' => ['postal_code', '123'],
    'long postal code' => ['postal_code', '12345'],
    'nonnumeric postal code' => ['postal_code', 'ABCD'],
    'unsupported country' => ['country_code', 'US'],
    'invalid default flag' => ['is_default', 'invalid'],
]);

it('rejects invalid address fields on both creation and update', function (
    string $field,
    mixed $value,
) {
    $customer = customerAddressAccount();

    $address = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => true,
    ]);

    $before = $address->fresh()->getRawOriginal();

    customerAddressLogin($this, $customer->user);

    $this->postJson('/api/customer/addresses', customerAddressPayload([
        $field => $value,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    $this->patchJson('/api/customer/addresses/'.$address->id, [
        'label' => 'Must Not Persist',
        $field => $value,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect($customer->addresses()->count())->toBe(1);
    expect($address->fresh()->getRawOriginal())->toBe($before);
})->with('customers.address.invalid_fields');

it('enforces address field length limits without partial writes', function () {
    $customer = customerAddressAccount();

    $address = Address::factory()->create([
        'customer_id' => $customer->id,
        'is_default' => true,
    ]);

    $before = $address->fresh()->getRawOriginal();

    customerAddressLogin($this, $customer->user);

    $invalid = [
        'recipient_name' => str_repeat('R', 151),
        'address_line1' => str_repeat('A', 256),
        'address_line2' => str_repeat('B', 256),
        'city' => str_repeat('C', 101),
        'district' => str_repeat('D', 101),
        'division' => str_repeat('V', 101),
        'upazila' => str_repeat('U', 101),
        'area' => str_repeat('A', 101),
        'label' => str_repeat('L', 51),
        'delivery_instructions' => str_repeat('I', 1001),
    ];

    $this->postJson(
        '/api/customer/addresses',
        customerAddressPayload($invalid),
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(array_keys($invalid));

    $this->patchJson('/api/customer/addresses/'.$address->id, $invalid)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(array_keys($invalid));

    expect($customer->addresses()->count())->toBe(1);
    expect($address->fresh()->getRawOriginal())->toBe($before);
});

it('accepts address fields at their documented maximum lengths', function () {
    $customer = customerAddressAccount();

    customerAddressLogin($this, $customer->user);

    $payload = customerAddressPayload([
        'recipient_name' => str_repeat('R', 150),
        'address_line1' => str_repeat('A', 255),
        'address_line2' => str_repeat('B', 255),
        'city' => str_repeat('C', 100),
        'district' => str_repeat('D', 100),
        'division' => str_repeat('V', 100),
        'upazila' => str_repeat('U', 100),
        'area' => str_repeat('A', 100),
        'label' => str_repeat('L', 50),
        'delivery_instructions' => str_repeat('I', 1000),
    ]);

    $response = $this->postJson('/api/customer/addresses', $payload)
        ->assertCreated();

    foreach ($payload as $field => $value) {
        $response->assertJsonPath('data.address.'.$field, $value);
    }

    $this->assertDatabaseHas('addresses', [
        'id' => $response->json('data.address.id'),
        'customer_id' => $customer->id,
        ...$payload,
    ]);
});

it('allows authorized staff to manage addresses for the selected customer', function () {
    $customer = customerAddressAccount();

    customerAddressStaff($this);

    $base = '/api/customers/'.$customer->id.'/addresses';

    $id = $this->postJson($base, customerAddressPayload())
        ->assertCreated()
        ->json('data.address.id');

    $this->assertDatabaseHas('addresses', [
        'id' => $id,
        'customer_id' => $customer->id,
    ]);

    $this->getJson($base)
        ->assertOk()
        ->assertJsonCount(1, 'data.addresses')
        ->assertJsonPath('data.pagination.total', 1);

    $this->getJson($base.'/'.$id)
        ->assertOk()
        ->assertJsonPath('data.address.id', $id);

    $this->patchJson($base.'/'.$id, ['city' => 'Gazipur'])
        ->assertOk()
        ->assertJsonPath('data.address.city', 'Gazipur');

    $this->deleteJson($base.'/'.$id)->assertOk();

    expect($customer->addresses()->whereKey($id)->exists())->toBeFalse();
});

it('scopes staff address operations to the customer in the URL', function () {
    $customer = customerAddressAccount();
    $other = customerAddressAccount();

    $foreign = Address::factory()->create([
        'customer_id' => $other->id,
        'is_default' => true,
    ]);

    $before = $foreign->fresh()->getRawOriginal();

    customerAddressStaff($this);

    $base = '/api/customers/'.$customer->id.'/addresses';

    $this->getJson($base.'/'.$foreign->id)->assertNotFound();

    $this->patchJson($base.'/'.$foreign->id, [
        'city' => 'Unauthorized City',
    ])->assertNotFound();

    $this->deleteJson($base.'/'.$foreign->id)->assertNotFound();

    $this->postJson($base, customerAddressPayload([
        'customer_id' => $other->id,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('customer_id');

    $this->getJson($base)
        ->assertOk()
        ->assertJsonCount(0, 'data.addresses');

    expect($foreign->fresh()->getRawOriginal())->toBe($before);
    expect($customer->addresses()->count())->toBe(0);
});

it('returns not found for missing addresses in both route groups', function () {
    $customer = customerAddressAccount();

    $missingId = ((int) Address::max('id')) + 1000;

    customerAddressLogin($this, $customer->user);

    $selfPath = '/api/customer/addresses/'.$missingId;

    $this->getJson($selfPath)->assertNotFound();
    $this->patchJson($selfPath, ['city' => 'Dhaka'])->assertNotFound();
    $this->deleteJson($selfPath)->assertNotFound();

    customerAddressStaff($this);

    $staffPath = '/api/customers/'.$customer->id.'/addresses/'.$missingId;

    $this->getJson($staffPath)->assertNotFound();
    $this->patchJson($staffPath, ['city' => 'Dhaka'])->assertNotFound();
    $this->deleteJson($staffPath)->assertNotFound();
});

it('validates address pagination in both route groups', function () {
    $customer = customerAddressAccount();

    customerAddressLogin($this, $customer->user);

    $invalidQueries = [
        [['page' => 0], 'page'],
        [['per_page' => 0], 'per_page'],
        [['per_page' => 101], 'per_page'],
    ];

    foreach ($invalidQueries as [$query, $field]) {
        $this->getJson(
            '/api/customer/addresses?'.http_build_query($query),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    customerAddressStaff($this);

    foreach ($invalidQueries as [$query, $field]) {
        $this->getJson(
            '/api/customers/'.$customer->id.'/addresses?'.
            http_build_query($query),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }
});
