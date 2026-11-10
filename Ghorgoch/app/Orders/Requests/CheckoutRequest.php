<?php

namespace App\Orders\Requests;

use App\Customers\Requests\AddressRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->has('delivery_zone')) {
            return [
                'delivery_zone' => ['required', Rule::in(['inside_dhaka', 'outside_dhaka'])],
                'contact' => ['required', 'array:name,email,phone'],
                'contact.name' => ['required', 'string', 'max:150'],
                'contact.phone' => ['required', 'string', 'regex:/^\+8801[3-9][0-9]{8}$/'],
                'contact.email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:255'],
                'delivery' => ['sometimes', 'array:address_line1'],
                'delivery.address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
                'payment_method' => ['required', Rule::in(['cod'])],
                'customer_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
                ...($this->route()->getActionMethod() === 'store' ? [
                    'review_token' => ['required', 'string', 'max:4096'],
                    'idempotency_key' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
                ] : []),
            ];
        }
        $guest = $this->user() === null;
        $rules = [
            'payment_method' => ['required', Rule::in(['cod'])],
            'customer_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'address_id' => ['sometimes', 'integer', Rule::prohibitedIf($guest || $this->has('delivery'))],
            'delivery' => ['required_without:address_id', 'array:'.implode(',', array_diff(array_keys(AddressRequest::deliveryRules()), ['is_default', 'label']))],
            'contact' => [$guest ? 'required' : 'prohibited', 'array:name,email,phone'],
        ];
        foreach (AddressRequest::deliveryRules() as $field => $fieldRules) {
            if (in_array($field, ['is_default', 'label'], true)) {
                continue;
            }
            $rules['delivery.'.$field] = array_map(fn ($rule) => $rule === 'required' ? 'required_with:delivery' : $rule, $fieldRules);
        }
        $rules['contact.name'] = ['required_with:contact', 'string', 'max:150'];
        $rules['contact.phone'] = ['required_with:contact', 'string', 'regex:/^\+8801[3-9][0-9]{8}$/'];
        $rules['contact.email'] = ['sometimes', 'nullable', 'string', 'email:rfc', 'max:255'];
        if ($this->route()->getActionMethod() === 'store') {
            $rules['review_token'] = ['required', 'string', 'max:4096'];
            $rules['idempotency_key'] = ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_filter(array_keys($this->rules()), fn (string $key): bool => ! str_contains($key, '.'));
            foreach (array_diff(array_keys($this->all()), [...$allowed, '_token']) as $field) {
                $validator->errors()->add($field, 'This field is not allowed.');
            }
        }];
    }
}
