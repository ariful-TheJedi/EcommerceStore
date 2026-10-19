<?php

namespace App\Users\Domain\Services;

use App\Auth\Domain\Services\UserProvisioning;
use App\Users\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffService
{
    public function __construct(private readonly StaffPermissions $permissions, private readonly UserProvisioning $provisioning) {}

    public function create(User $principal, array $data): User
    {
        return $this->transaction(function () use ($principal, $data): User {
            $actor = $this->actor($principal);
            $this->authorizeAssignments($actor, null, $data);
            $user = $this->provisioning->create($data);
            $user->avatar_path = $data['avatar_path'] ?? null;
            $user->permissions = $data['permissions'] ?? [];
            $user->save();
            $this->audit($actor, $user, 'created', null);

            return $user;
        });
    }

    public function update(User $principal, int $id, array $data, bool $self = false): User
    {
        return $this->transaction(function () use ($principal, $id, $data, $self): User {
            $actor = $this->actor($principal, ! $self);
            $user = User::whereIn('role', ['admin', 'staff'])->lockForUpdate()->findOrFail($id);
            abort_if($self && $actor->id !== $user->id, 403);
            if (! $self) {
                $this->authorizeAssignments($actor, $user, $data);
            }
            $before = $this->snapshot($user);
            if ($user->role === 'admin' && $user->is_active
                && (($data['role'] ?? 'admin') !== 'admin' || ($data['is_active'] ?? true) === false)) {
                abort_if(! User::where('role', 'admin')->where('is_active', true)->where('id', '!=', $user->id)->exists(), 409, 'The last active administrator must remain active.');
            }
            foreach ($data as $key => $value) {
                $user->setAttribute($key, $value);
            }
            $user->save();
            $after = $this->snapshot($user);
            if ($before !== $after) {
                $action = $before['is_active'] !== $after['is_active']
                    ? ($after['is_active'] ? 'reactivated' : 'disabled')
                    : 'updated';
                $this->audit($actor, $user, $action, $before);
                if ($before['role'] !== $after['role'] || $before['permissions'] !== $after['permissions']) {
                    $this->audit($actor, $user, 'access_changed', $before);
                }
            }

            return $user;
        });
    }

    private function actor(User $principal, bool $manage = true): User
    {
        $actor = User::lockForUpdate()->findOrFail($principal->id);
        abort_unless($actor->is_active && in_array($actor->role, ['admin', 'staff'], true), 403);
        abort_if($manage && ! $this->permissions->allows($actor, 'staff.manage'), 403);

        return $actor;
    }

    private function authorizeAssignments(User $actor, ?User $target, array $data): void
    {
        abort_if($target?->role === 'admin' && $actor->role !== 'admin', 403);
        $authority = $this->permissions->effective($actor);
        $targetPermissions = $target?->role === 'admin'
            ? config('staff.permissions')
            : array_intersect($target?->permissions ?? [], config('staff.permissions'));
        abort_if($target && array_diff($targetPermissions, $authority) !== [], 403);
        $roleChanged = array_key_exists('role', $data) && $data['role'] !== ($target?->role ?? 'staff');
        $permissionsChanged = array_key_exists('permissions', $data) && $data['permissions'] !== ($target?->permissions ?? []);
        if ($roleChanged) {
            abort_unless($actor->role === 'admin' && $this->permissions->allows($actor, 'roles.assign'), 403);
        }
        if ($permissionsChanged) {
            abort_unless($actor->role === 'admin' && $this->permissions->allows($actor, 'permissions.assign'), 403);
        }
        if ($target?->id === $actor->id && ($roleChanged || $permissionsChanged)) {
            abort_unless($this->permissions->allows($actor, 'staff.assign_self'), 403);
        }
        abort_if(array_diff($data['permissions'] ?? [], $authority) !== [], 403);
    }

    private function snapshot(User $user): array
    {
        return [
            'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone,
            'avatar_path' => $user->avatar_path, 'role' => $user->role,
            'is_active' => $user->is_active, 'permissions' => $user->permissions ?? [],
        ];
    }

    private function audit(User $actor, User $user, string $action, ?array $before): void
    {
        DB::table('staff_activity')->insert([
            'actor_id' => $actor->id, 'staff_id' => $user->id, 'action' => $action,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => json_encode($this->snapshot($user), JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function transaction(\Closure $callback): User
    {
        try {
            return DB::transaction(function () use ($callback): User {
                DB::table('staff_security_locks')->where('id', 1)->lockForUpdate()->first();

                return $callback();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'The email or phone has already been taken.']);
        }
    }
}
