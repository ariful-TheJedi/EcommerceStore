<?php

namespace App\Auth\Requests;

use App\Users\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class AuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->route()->getActionMethod() === 'store') {
            return $this->user()?->role === 'admin';
        }

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
        $credentialPassword = ['required', 'string', $byteLimit];

        $uniqueEmail = [...$email, function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && User::whereRaw('LOWER(email) = ?', [$value])->exists()) {
                $fail('The email has already been taken.');
            }
        }];

        return match ($this->route()->getActionMethod()) {
            'store' => [
                'name' => ['required', 'string', 'max:150'],
                'email' => $uniqueEmail,
                'phone' => ['nullable', 'string', 'regex:/^\+8801[3-9][0-9]{8}$/', Rule::unique('users', 'phone')],
                'password' => $password,
                'password_confirmation' => ['required', 'string'],
                'role' => ['required', Rule::in(['admin', 'staff', 'customer'])],
            ],
            'login' => ['email' => $email, 'password' => $credentialPassword],
            'forgotPassword' => ['email' => $email],
            'resetPassword' => [
                'email' => $email,
                'token' => ['required', 'string', 'size:64'],
                'password' => $password,
                'password_confirmation' => ['required', 'string'],
            ],
            'changePassword' => [
                'current_password' => $credentialPassword,
                'password' => [...$password, 'different:current_password'],
                'password_confirmation' => ['required', 'string'],
            ],
            'verifyEmail' => ['token' => ['required', 'string', 'size:64']],
            'changeEmail' => ['email' => $uniqueEmail, 'current_password' => $credentialPassword],
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
