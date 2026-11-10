<?php

namespace App\Customers\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }
        if ($this->route()->defaults['customer_self'] ?? false) {
            return $this->user()->role === 'customer' && $this->user()->is_active;
        }

        return Gate::forUser($this->user())->allows(in_array($this->route()->getActionMethod(), ['index', 'show'], true) ? 'customers.view' : 'customers.manage');
    }

    public function rules(): array
    {
        $action = $this->route()->getActionMethod();
        if ($action === 'index') {
            return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
        }
        if (! in_array($action, ['store', 'update'], true)) {
            return [];
        }
        $required = $action === 'store' ? 'required' : 'sometimes';

        return self::deliveryRules($required);
    }

    public static function deliveryRules(string $required = 'required'): array
    {
        return [
            'recipient_name' => [$required, 'string', 'max:150'],
            'phone' => [$required, 'string', 'regex:/^\+8801[3-9][0-9]{8}$/'],
            'address_line1' => [$required, 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => [$required, 'string', 'max:100'],
            'district' => [$required, 'string', 'max:100'],
            'division' => [$required, 'string', 'max:100'],
            'upazila' => ['sometimes', 'nullable', 'string', 'max:100'],
            'area' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{4}$/'],
            'country_code' => ['sometimes', Rule::in(['BD'])],
            'label' => ['sometimes', 'nullable', 'string', 'max:50'],
            'delivery_instructions' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token']) as $field) {
                $validator->errors()->add($field, 'This field is not allowed.');
            }
        }];
    }
}
