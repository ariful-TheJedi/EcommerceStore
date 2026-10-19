<?php

namespace App\Users\Domain\Services;

use App\Users\Models\User;

class StaffPermissions
{
    public function effective(User $user): array
    {
        if (! $user->is_active || ! in_array($user->role, ['admin', 'staff'], true)) {
            return [];
        }

        return $user->role === 'admin'
            ? config('staff.permissions')
            : array_values(array_intersect($user->permissions ?? [], config('staff.permissions')));
    }

    public function allows(User $user, string $permission): bool
    {
        return in_array($permission, $this->effective($user), true);
    }
}
