<?php

namespace App\Customers\Requests;

use App\Users\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class RegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $email = ['required', 'string', 'email:rfc', 'max:255'];
        $byteLimit = function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && strlen($value) > 72) {
                $fail('The password must not exceed 72 bytes.');
            }
        };
        $password = ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols(), 'confirmed', $byteLimit];

        $uniqueEmail = [...$email, function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && User::whereRaw('LOWER(email) = ?', [$value])->exists()) {
                $fail('The email has already been taken.');
            }
        }];

        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => $uniqueEmail,
            'phone' => ['nullable', 'string', 'regex:/^\+8801[3-9][0-9]{8}$/', Rule::unique('users', 'phone')],
            'password' => $password,
            'password_confirmation' => ['required', 'string'],
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
