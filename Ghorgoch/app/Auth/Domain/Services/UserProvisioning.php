<?php

namespace App\Auth\Domain\Services;

use App\Users\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UserProvisioning
{
    public function create(array $data): User
    {
        abort_if($data['role'] === 'customer' && ! config('auth.customer_enabled'), 403);

        try {
            $user = DB::transaction(function () use ($data): User {
                $user = new User;
                $user->name = $data['name'];
                $user->email = $data['email'];
                $user->phone = $data['phone'] ?? null;
                $user->password = $data['password'];
                $user->role = $data['role'];
                $user->is_active = true;
                $user->save();
                if ($user->role === 'customer') {
                    $user->customer()->create([]);
                }

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'The email or phone has already been taken.']);
        }

        Log::info('auth.user_provisioned', ['user_id' => $user->id, 'role' => $user->role]);

        return $user;
    }
}
