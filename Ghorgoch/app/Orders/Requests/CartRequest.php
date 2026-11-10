<?php

namespace App\Orders\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route()->getActionMethod() !== 'merge' || $this->user()?->role === 'customer';
    }

    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'sync' => ['items' => ['required', 'array', 'min:1', 'max:'.config('orders.max_lines')],
                'items.*' => ['required', 'array:variant_id,quantity'],
                'items.*.variant_id' => ['required', 'integer', 'distinct', 'exists:product_variants,id'],
                'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.config('orders.max_quantity')]],
            'store' => ['variant_id' => ['required', 'integer', 'exists:product_variants,id'], 'quantity' => ['required', 'integer', 'min:1', 'max:'.config('orders.max_quantity')]],
            'update' => ['quantity' => ['required', 'integer', 'min:1', 'max:'.config('orders.max_quantity')]],
            default => [],
        };
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
