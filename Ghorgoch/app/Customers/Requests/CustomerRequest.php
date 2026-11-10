<?php

namespace App\Customers\Requests;

use App\Customers\Domain\Services\CustomerService;
use App\Users\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }
        if ($this->route()->defaults['customer_self'] ?? false) {
            return $this->user()->role === 'customer' && $this->user()->is_active;
        }
        $permission = match ($this->route()->getActionMethod()) {
            'index', 'show', 'statusHistory' => 'customers.view',
            'notes', 'storeNote' => 'customers.notes',
            default => 'customers.manage',
        };

        return Gate::forUser($this->user())->allows($permission);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $action = $this->route()->getActionMethod();
        $self = $this->route()->defaults['customer_self'] ?? false;
        $page = ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
        if (in_array($action, ['index', 'notes', 'statusHistory'], true)) {
            return $page + ($action === 'index' ? [
                'search' => ['sometimes', 'string', 'max:150'],
                'status' => ['sometimes', Rule::in(['active', 'blocked', 'closed'])],
            ] : []);
        }
        if (in_array($action, ['block', 'unblock'], true) || ($action === 'close' && ! $self)) {
            return ['reason' => ['required', 'string', 'max:1000']];
        }
        if ($action === 'close') {
            return ['current_password' => ['required', 'string', 'max:255']];
        }
        if ($action === 'storeNote') {
            return ['body' => ['required', 'string', 'max:5000']];
        }
        if (! in_array($action, ['store', 'update', 'updateMe'], true)) {
            return [];
        }
        $creating = $action === 'store';
        $id = $creating ? null : app(CustomerService::class)->resolve($this->user(), $self ? null : (int) $this->route('customer'), true)->user_id;
        $rules = [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+8801[3-9][0-9]{8}$/', Rule::unique('users', 'phone')->ignore($id)],
            'avatar_path' => ['sometimes', 'nullable', 'string', 'max:500'],
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
        if (! $self) {
            $rules['email'] = [$creating ? 'required' : 'sometimes', 'string', 'email:rfc', 'max:255',
                function (string $attribute, mixed $value, Closure $fail) use ($id): void {
                    if (is_string($value) && User::whereRaw('LOWER(email) = ?', [$value])->when($id, fn ($query) => $query->whereKeyNot($id))->exists()) {
                        $fail('The email has already been taken.');
                    }
                },
            ];
        }

        return $rules;
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
