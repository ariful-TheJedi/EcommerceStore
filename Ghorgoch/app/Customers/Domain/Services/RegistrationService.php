<?php

namespace App\Customers\Domain\Services;

use App\Users\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RegistrationService
{
    public function register(array $data): User
    {
        try {
            $user = DB::transaction(function () use ($data): User {
                $user = new User;
                $user->name = $data['name'];
                $user->email = $data['email'];
                $user->phone = $data['phone'] ?? null;
                $user->password = $data['password'];
                $user->role = 'customer';
                $user->is_active = true;
                $user->save();
                $user->customer()->create([]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'The email or phone has already been taken.']);
        }

        event(new Registered($user));
        Log::info('customers.registered', ['user_id' => $user->id, 'role' => $user->role]);

        return $user;
    }
}
