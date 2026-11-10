<?php

namespace App\Orders\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DeliveryChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->isMethod('GET') ? $this->user()?->can('orders.view') === true : $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return $this->isMethod('GET') ? [] : [
            'inside_dhaka' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'outside_dhaka' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'version' => ['required', 'integer', 'min:0'],
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
