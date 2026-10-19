<?php

namespace App\Users\Requests;

use App\Users\Domain\Services\StaffPermissions;
use App\Users\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = match ($this->route()->getActionMethod()) {
            'me', 'updateMe' => null,
            'index', 'show', 'activity' => 'staff.view',
            'capabilities' => 'roles.assign',
            default => 'staff.manage',
        };

        return $this->user() !== null && ($permission === null || app(StaffPermissions::class)->allows($this->user(), $permission));
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
        if (in_array($action, ['index', 'activity'], true)) {
            return [
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
                ...($action === 'index' ? [
                    'search' => ['sometimes', 'string', 'max:150'],
                    'role' => ['sometimes', Rule::in(['admin', 'staff'])],
                    'status' => ['sometimes', Rule::in(['active', 'disabled'])],
                ] : []),
            ];
        }
        if (! in_array($action, ['store', 'update', 'updateMe'], true)) {
            return [];
        }

        $creating = $action === 'store';
        $id = $action === 'updateMe' ? $this->user()->id : $this->route('staff');
        $rules = [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\\+8801[3-9][0-9]{8}$/', Rule::unique('users', 'phone')->ignore($id)],
            'avatar_path' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
        if ($action === 'updateMe') {
            return $rules;
        }

        $rules['email'] = [$creating ? 'required' : 'sometimes', 'string', 'email:rfc', 'max:255',
            function (string $attribute, mixed $value, Closure $fail) use ($id): void {
                if (User::whereRaw('LOWER(email) = ?', [$value])->when($id, fn ($query) => $query->where('id', '!=', $id))->exists()) {
                    $fail('The email has already been taken.');
                }
            },
        ];
        $rules['role'] = [$creating ? 'required' : 'sometimes', Rule::in(['admin', 'staff'])];
        $rules['permissions'] = ['sometimes', 'array', 'max:14'];
        $rules['permissions.*'] = ['required', 'string', 'distinct', Rule::in(config('staff.permissions'))];
        if ($creating) {
            $rules['password'] = ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols(), 'confirmed',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && strlen($value) > 72) {
                        $fail('The password must not exceed 72 bytes.');
                    }
                },
            ];
            $rules['password_confirmation'] = ['required', 'string'];
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
