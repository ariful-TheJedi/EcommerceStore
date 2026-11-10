<?php

namespace App\Customers\Domain\Services;

use App\Customers\Models\Customer;
use App\Users\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    public function resolve(User $actor, ?int $id = null, bool $write = false): Customer
    {
        if ($id === null) {
            abort_unless($actor->role === 'customer' && $actor->is_active, 403);

            return $actor->customer()->with('user')->firstOrFail();
        }
        Gate::forUser($actor)->authorize($write ? 'customers.manage' : 'customers.view');

        return Customer::with('user')->whereHas('user', fn ($query) => $query->where('role', 'customer'))->findOrFail($id);
    }

    public function create(User $actor, array $data): Customer
    {
        Gate::forUser($actor)->authorize('customers.manage');

        return $this->unique(function () use ($data): Customer {
            return DB::transaction(function () use ($data): Customer {
                $user = new User;
                $user->name = $data['name'];
                $user->email = strtolower(trim($data['email']));
                $user->phone = $data['phone'] ?? null;
                $user->avatar_path = $data['avatar_path'] ?? null;
                $user->password = Str::random(64);
                $user->role = 'customer';
                $user->is_active = true;
                $user->save();
                $customer = new Customer;
                $customer->user_id = $user->id;
                $customer->date_of_birth = $data['date_of_birth'] ?? null;
                $customer->save();

                return $customer->load('user');
            });
        });
    }

    public function update(Customer $customer, array $data): Customer
    {
        return $this->unique(function () use ($customer, $data): Customer {
            return $this->locked($customer, function (Customer $customer, User $user) use ($data): Customer {
                abort_if($customer->closed_at !== null, 409);
                foreach (['name', 'email', 'phone', 'avatar_path'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $user->{$field} = $data[$field];
                    }
                }
                $user->save();
                if (array_key_exists('date_of_birth', $data)) {
                    $customer->date_of_birth = $data['date_of_birth'];
                    $customer->save();
                }

                return $customer->setRelation('user', $user);
            });
        });
    }

    public function status(Customer $customer, User $actor, bool $blocked, string $reason): Customer
    {
        Gate::forUser($actor)->authorize('customers.manage');

        return $this->locked($customer, function (Customer $customer, User $user) use ($actor, $blocked, $reason): Customer {
            abort_if($customer->closed_at !== null, 409);
            $user->is_active = ! $blocked;
            $user->save();
            $customer->blocked_at = $blocked ? now() : null;
            $customer->blocked_reason = $blocked ? $reason : null;
            $customer->blocked_by = $blocked ? $actor->id : null;
            $customer->save();
            $this->recordStatus($customer, $actor, $blocked ? 'blocked' : 'unblocked', $reason);

            return $customer->setRelation('user', $user);
        });
    }

    public function close(Customer $customer, User $actor, array $data): void
    {
        $self = $actor->role === 'customer' && $actor->id === $customer->user_id;
        if (! $self) {
            Gate::forUser($actor)->authorize('customers.manage');
        }
        $this->locked($customer, function (Customer $customer, User $user) use ($actor, $data, $self): void {
            abort_if($customer->closed_at !== null, 409);
            if ($self && ! Hash::check($data['current_password'] ?? '', $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
            }
            $this->recordStatus($customer, $actor, 'closed', $self ? 'Closed by account owner.' : $data['reason']);
            $customer->addresses()->delete();
            DB::table('customer_notes')->where('customer_id', $customer->id)->delete();
            DB::table('customer_status_history')->where('customer_id', $customer->id)->update(['reason' => 'Account anonymized.']);
            $customer->date_of_birth = null;
            $customer->blocked_reason = null;
            $customer->closed_at = now();
            $customer->save();
            $user->name = 'Closed customer';
            $user->email = 'closed-'.$user->id.'-'.Str::lower(Str::random(20)).'@example.invalid';
            $user->phone = null;
            $user->avatar_path = null;
            $user->password = Str::random(64);
            $user->is_active = false;
            $user->save();
        });
    }

    public function note(Customer $customer, User $actor, string $body): int
    {
        Gate::forUser($actor)->authorize('customers.notes');

        return $this->locked($customer, function (Customer $customer) use ($actor, $body): int {
            abort_if($customer->closed_at !== null, 409);

            return DB::table('customer_notes')->insertGetId([
                'customer_id' => $customer->id, 'actor_id' => $actor->id,
                'body' => $body, 'created_at' => now(),
            ]);
        });
    }

    private function recordStatus(Customer $customer, User $actor, string $action, string $reason): void
    {
        DB::table('customer_status_history')->insert([
            'customer_id' => $customer->id, 'actor_id' => $actor->id,
            'action' => $action, 'reason' => $reason, 'created_at' => now(),
        ]);
    }

    private function locked(Customer $customer, \Closure $callback): mixed
    {
        return DB::transaction(function () use ($customer, $callback): mixed {
            $user = User::whereKey($customer->user_id)->lockForUpdate()->firstOrFail();
            abort_unless($user->role === 'customer', 404);
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();

            return $callback($customer, $user);
        }, 3);
    }

    private function unique(\Closure $callback): Customer
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'The email or phone has already been taken.']);
        }
    }
}
