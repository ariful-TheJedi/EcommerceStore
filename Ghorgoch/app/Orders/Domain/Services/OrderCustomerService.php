<?php

namespace App\Orders\Domain\Services;

use App\Customers\Models\Customer;
use App\Customers\Requests\AddressRequest;
use App\Users\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OrderCustomerService
{
    public function registered(User $principal, int $addressId, Closure $placeOrder): mixed
    {
        return DB::transaction(function () use ($principal, $addressId, $placeOrder): mixed {
            $user = User::whereKey($principal->id)->lockForUpdate()->firstOrFail();
            $customer = $user->customer()->lockForUpdate()->firstOrFail();
            $this->assertCanOrder($customer, $user);
            $address = $customer->addresses()->findOrFail($addressId);
            $snapshot = $address->only([
                'recipient_name', 'phone', 'address_line1', 'address_line2', 'area',
                'city', 'district', 'division', 'upazila', 'postal_code', 'country_code', 'delivery_instructions',
            ]);

            return $placeOrder(['customer_id' => $customer->id, 'shipping_address' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        }, 3);
    }

    public function guest(array $contact, array $delivery, Closure $placeOrder): mixed
    {
        abort_if(Auth::guard('customer')->check(), 403);
        $contact = $this->validate($contact, [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\+8801[3-9][0-9]{8}$/'],
        ]);
        $delivery = $this->validate($delivery, AddressRequest::deliveryRules());
        $delivery['country_code'] ??= 'BD';
        unset($delivery['is_default'], $delivery['label']);

        return DB::transaction(fn () => $placeOrder([
            'customer_id' => null, 'guest_name' => $contact['name'],
            'guest_email' => isset($contact['email']) ? strtolower(trim($contact['email'])) : null,
            'guest_phone' => $contact['phone'], 'shipping_address' => json_encode($delivery, JSON_THROW_ON_ERROR),
        ]), 3);
    }

    public function assertCanOrder(Customer $customer, ?User $user = null): void
    {
        $user ??= $customer->user;
        if ($customer->closed_at !== null || $user === null || $user->role !== 'customer' || ! $user->is_active) {
            throw ValidationException::withMessages(['customer' => 'This customer cannot place new orders.']);
        }
    }

    private function validate(array $data, array $rules): array
    {
        $validator = Validator::make($data, $rules);
        $validator->after(function ($validator) use ($data, $rules): void {
            foreach (array_diff(array_keys($data), array_keys($rules)) as $field) {
                $validator->errors()->add($field, 'This field is not allowed.');
            }
        });

        return $validator->validate();
    }
}
