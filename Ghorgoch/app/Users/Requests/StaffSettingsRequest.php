<?php

namespace App\Users\Requests;

use App\Users\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StaffSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && in_array($this->user()->role, ['admin', 'staff'], true)
            && (! $this->has('role') || $this->user()->role === 'admin');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->route()->getActionMethod() === 'avatar') {
            return $this->isMethod('DELETE') ? [] : ['avatar' => ['bail', 'required', 'image', 'mimes:png,jpg,jpeg', 'extensions:png,jpg,jpeg', 'max:2048', 'dimensions:min_width=1,min_height=1']];
        }
        $sensitive = $this->has('password') || ($this->has('email') && $this->input('email') !== $this->user()->email)
            || ($this->has('role') && $this->input('role') !== $this->user()->role);

        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'email' => ['bail', 'sometimes', 'required', 'string', 'email:rfc', 'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (User::whereRaw('LOWER(email) = ?', [$value])->where('id', '!=', $this->user()->id)->exists()) {
                        $fail('The email has already been taken.');
                    }
                }],
            'current_password' => [Rule::requiredIf($sensitive), 'nullable', 'string', 'max:1000'],
            'password' => ['sometimes', 'required', 'string', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols(),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && strlen($value) > 72) {
                        $fail('The password must not exceed 72 bytes.');
                    }
                }],
            'password_confirmation' => ['required_with:password', 'string'],
            'role' => ['sometimes', 'required', Rule::in(['admin', 'staff'])],
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
