<?php

namespace App\Auth\Domain\Services;

use App\Users\Models\User;
use Illuminate\Support\Facades\Validator;

class BootstrapAdmin
{
    public function __construct(private readonly UserProvisioning $users) {}

    public function create(): User
    {
        $data = config('auth.bootstrap_admin');
        $data['email'] = strtolower(trim($data['email'] ?? ''));
        Validator::make($data, [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (User::whereRaw('LOWER(email) = ?', [$value])->exists()) {
                    $fail('An account with this email already exists; it will not be overwritten.');
                }
            }],
            'password' => ['required', 'string', 'min:12', 'max:72'],
        ])->validate();

        return $this->users->create([...$data, 'role' => 'admin']);
    }
}
