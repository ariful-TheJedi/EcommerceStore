<?php

namespace App\Customers\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class CustomerHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }
        if ($this->route()->defaults['customer_self'] ?? false) {
            return $this->user()->role === 'customer' && $this->user()->is_active;
        }

        return Gate::forUser($this->user())->allows('customers.view')
            && Gate::forUser($this->user())->allows($this->route()->defaults['summary_permission']);
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not allowed.');
            }
        }];
    }
}
