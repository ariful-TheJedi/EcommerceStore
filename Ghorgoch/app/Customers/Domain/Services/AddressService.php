<?php

namespace App\Customers\Domain\Services;

use App\Customers\Models\Address;
use App\Customers\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddressService
{
    public function save(Customer $customer, array $data, ?int $id = null): Address
    {
        return DB::transaction(function () use ($customer, $data, $id): Address {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            abort_if($customer->closed_at !== null, 409);
            $address = $id === null ? new Address : $customer->addresses()->findOrFail($id);
            $default = $customer->addresses()->where('is_default', true)->first();
            if ($address->exists && $address->is_default && array_key_exists('is_default', $data) && ! $data['is_default']) {
                throw ValidationException::withMessages(['is_default' => 'Choose another default address before clearing this default.']);
            }
            $makeDefault = ($data['is_default'] ?? false) || $default === null;
            if ($makeDefault) {
                $customer->addresses()->where('is_default', true)
                    ->when($address->exists, fn ($query) => $query->whereKeyNot($address->id))
                    ->update(['is_default' => false]);
            }
            $address->fill($data);
            $address->customer_id = $customer->id;
            $address->is_default = $makeDefault || ($address->exists && $default?->id === $address->id);
            $address->save();

            return $address->fresh();
        }, 3);
    }

    public function delete(Customer $customer, int $id): void
    {
        DB::transaction(function () use ($customer, $id): void {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            abort_if($customer->closed_at !== null, 409);
            $address = $customer->addresses()->findOrFail($id);
            $wasDefault = $address->is_default;
            $address->delete();
            if ($wasDefault) {
                $next = $customer->addresses()->orderBy('id')->first();
                if ($next !== null) {
                    $next->is_default = true;
                    $next->save();
                }
            }
        }, 3);
    }
}
