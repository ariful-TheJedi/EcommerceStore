<?php

namespace App\Orders\Requests;

use App\Customers\Requests\AddressRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class OrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $audience = $this->route()->defaults['order_audience'];
        if ($audience === 'guest') {
            return true;
        }
        if ($audience === 'customer') {
            return $this->user()?->role === 'customer';
        }

        return $this->user() !== null && Gate::forUser($this->user())->allows(in_array($this->route()->getActionMethod(), ['index', 'show', 'report'], true) ? 'orders.view' : 'orders.process');
    }

    public function rules(): array
    {
        $action = $this->route()->getActionMethod();
        if ($action === 'bulkDestroy') {
            return ['order_ids' => ['required', 'array', 'min:1', 'max:100'],
                'order_ids.*' => ['required', 'integer', 'distinct', Rule::exists('orders', 'id')->whereNull('deleted_at')],
                'reason' => ['required', 'string', 'max:1000']];
        }
        if ($action === 'store') {
            $rules = [
                'idempotency_key' => ['required', 'string', 'max:100'],
                'contact' => ['required', 'array:name,phone,email'],
                'contact.name' => ['required', 'string', 'max:150'],
                'contact.phone' => ['required', 'string', 'regex:/^\+8801[3-9][0-9]{8}$/'],
                'contact.email' => ['sometimes', 'nullable', 'email', 'max:255'],
                'items' => ['required', 'array', 'min:1', 'max:50'],
                'items.*' => ['required', 'array:variant_id,quantity'],
                'items.*.variant_id' => ['required', 'integer', 'distinct', 'exists:product_variants,id'],
                'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
                'shipping_fee' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
                'customer_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'delivery' => ['required', 'array:'.implode(',', array_diff(array_keys(AddressRequest::deliveryRules()), ['label', 'is_default']))],
            ];
            foreach (AddressRequest::deliveryRules() as $field => $fieldRules) {
                if (! in_array($field, ['label', 'is_default'], true)) {
                    $rules['delivery.'.$field] = $fieldRules;
                }
            }

            return $rules;
        }
        if ($action === 'destroy') {
            return ['reason' => ['required', 'string', 'max:1000']];
        }
        if (in_array($action, ['index', 'report'], true)) {
            return [
                'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'search' => ['sometimes', 'string', 'max:150'], 'phone' => ['sometimes', 'string', 'max:20'],
                'customer_id' => ['sometimes', 'integer', 'min:1'],
                'deletion_list' => ['sometimes', 'boolean'],
                'status' => ['sometimes', Rule::in(array_keys(config('orders.transitions')))],
                'payment_status' => ['sometimes', Rule::in(['unpaid', 'pending', 'paid', 'failed', 'partially_refunded', 'refunded'])],
                'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', Rule::when($this->has('from'), 'after_or_equal:from')],
            ];
        }
        if ($action === 'cancel') {
            return ['reason' => ['required', 'string', 'max:1000']];
        }
        if ($action === 'transition') {
            return ['status' => ['required', Rule::in(array_keys(config('orders.transitions')))],
                'reason' => ['required_if:status,cancelled,returned', 'string', 'max:1000'],
                'restock' => ['required_if:status,returned', 'boolean'],
                'note' => ['sometimes', 'nullable', 'string', 'max:1000']];
        }
        if ($action === 'finances') {
            return ['product_cost' => ['present', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
                'expense_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
                'note' => ['required', 'string', 'max:1000']];
        }
        if ($action === 'delivery') {
            return ['courier_name' => ['sometimes', 'nullable', 'string', 'max:100'],
                'tracking_reference' => ['sometimes', 'nullable', 'string', 'max:150'],
                'delivery_method' => ['sometimes', Rule::in(['standard'])],
                'delivery_status' => ['sometimes', Rule::in(['in_transit', 'failed'])],
                'note' => ['required_if:delivery_status,failed', 'nullable', 'string', 'max:1000']];
        }
        if ($action === 'address') {
            $rules = ['delivery' => ['required', 'array:'.implode(',', array_diff(array_keys(AddressRequest::deliveryRules()), ['label', 'is_default']))], 'note' => ['required', 'string', 'max:1000']];
            foreach (AddressRequest::deliveryRules() as $field => $fieldRules) {
                if (! in_array($field, ['label', 'is_default'], true)) {
                    $rules['delivery.'.$field] = $fieldRules;
                }
            }

            return $rules;
        }

        return $action === 'note' ? ['note' => ['required', 'string', 'max:5000']] : [];
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
