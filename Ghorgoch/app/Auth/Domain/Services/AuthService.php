<?php

namespace App\Auth\Domain\Services;

use App\Auth\Notifications\EmailVerificationNotification;
use App\Auth\Notifications\PasswordRecoveryNotification;
use App\Users\Models\EmailVerificationToken;
use App\Users\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthService
{
    public function login(Request $request, string $accountType, array $data): User
    {
        $user = (new Timebox)->call(function () use ($accountType, $data): ?User {
            $user = $this->account($accountType, $data['email']);
            if (! $user) {
                Hash::make($data['password']);

                return null;
            }
            $valid = Hash::check($data['password'], $user->password);

            return $valid && $user->is_active ? $user : null;
        }, 200000);

        if (! $user) {
            Log::notice('auth.login_failed', ['account_type' => $accountType]);
            throw new AuthenticationException('The provided credentials are invalid.');
        }

        Auth::shouldUse($accountType);
        $request->session()->invalidate();
        Auth::guard($accountType)->login($user);
        $request->session()->regenerateToken();
        $request->session()->put([
            'auth_started_at' => now()->timestamp,
            'auth_fingerprint' => $this->fingerprint($user),
        ]);
        $this->audit('logged_in', $user);

        return $user;
    }

    public function fingerprint(User $user): string
    {
        return hash_hmac('sha256', implode('|', [$user->password, $user->email, $user->role, json_encode($user->permissions ?? [], JSON_THROW_ON_ERROR), (int) $user->access_version]), config('app.key'));
    }

    public function logout(Request $request, string $accountType, bool $allDevices = false): void
    {
        $user = $request->user();
        if ($allDevices) {
            $this->revokeSessions($user);
        }
        Auth::guard($accountType)->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $this->audit($allDevices ? 'logged_out_all' : 'logged_out', $user);
    }

    public function revokeSessions(User $user): void
    {
        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->delete();
    }

    public function changePassword(User $principal, array $data): void
    {
        DB::transaction(function () use ($principal, $data): void {
            $user = User::whereKey($principal->id)->lockForUpdate()->firstOrFail();
            if (! $user->is_active || ! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
            }
            $user->password = $data['password'];
            $user->save();
            Password::broker('users')->deleteToken($user);
            $this->revokeSessions($user);
            $this->audit('password_changed', $user);
        });
    }

    public function forgotPassword(string $accountType, string $email): void
    {
        if (! config('auth.email_enabled')) {
            return;
        }

        $user = $this->account($accountType, $email);
        if (! $user || ! $user->is_active || ! $user->email_verified_at) {
            return;
        }

        Password::broker('users')->sendResetLink(['email' => $user->email], function (User $user, string $token) use ($accountType): void {
            $this->deliver($user, new PasswordRecoveryNotification($token, $accountType));
        });
    }

    public function changeEmail(User $principal, array $data): User
    {
        try {
            $user = DB::transaction(function () use ($principal, $data): User {
                $user = User::whereKey($principal->id)->lockForUpdate()->firstOrFail();
                if (! $user->is_active || ! Hash::check($data['current_password'], $user->password)) {
                    throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
                }
                $user->email = $data['email'];
                $user->email_verified_at = null;
                $user->save();
                $this->audit('email_changed', $user);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
        }

        return $user;
    }

    public function resetPassword(string $accountType, array $data): void
    {
        $user = DB::transaction(function () use ($accountType, $data): User {
            $user = User::whereRaw('LOWER(email) = ?', [$data['email']])->lockForUpdate()->first();
            $broker = Password::broker('users');
            if (! $user || ! $this->matchesType($user, $accountType) || ! $user->is_active
                || ! $user->email_verified_at || ! $broker->tokenExists($user, $data['token'])) {
                throw ValidationException::withMessages(['token' => 'The password reset token is invalid or expired.']);
            }

            $user->password = $data['password'];
            $user->save();
            $broker->deleteToken($user);
            $this->revokeSessions($user);
            $this->audit('password_reset', $user);

            return $user;
        });

        event(new PasswordReset($user));
    }

    public function sendVerification(User $principal): bool
    {
        if (! config('auth.email_enabled')) {
            return false;
        }

        $token = Str::random(64);
        $sent = DB::transaction(function () use ($principal, $token): bool {
            $user = User::whereKey($principal->id)->lockForUpdate()->firstOrFail();
            if ($user->email_verified_at) {
                return false;
            }

            EmailVerificationToken::updateOrCreate(['user_id' => $user->id], [
                'token_hash' => hash('sha256', $token.'|'.$user->email),
                'expires_at' => now()->addMinutes(config('auth.verification_expire')),
                'created_at' => now(),
            ]);

            return true;
        });

        return ! $sent || $this->deliver($principal, new EmailVerificationNotification($token));
    }

    public function verifyEmail(User $principal, string $token): void
    {
        $user = DB::transaction(function () use ($principal, $token): User {
            $user = User::whereKey($principal->id)->lockForUpdate()->firstOrFail();
            $verification = $user->emailVerificationToken()->lockForUpdate()->first();
            if (! $verification || $verification->expires_at->lessThanOrEqualTo(now())
                || ! hash_equals($verification->token_hash, hash('sha256', $token.'|'.$user->email))) {
                throw ValidationException::withMessages(['token' => 'The verification token is invalid or expired.']);
            }
            $user->email_verified_at = now();
            $user->save();
            $verification->delete();
            $this->audit('email_verified', $user);

            return $user;
        });

        event(new Verified($user));
    }

    public function matchesType(User $user, string $accountType): bool
    {
        return $accountType === 'staff'
            ? in_array($user->role, ['admin', 'staff'], true)
            : $user->role === 'customer';
    }

    private function account(string $accountType, string $email): ?User
    {
        return User::whereRaw('LOWER(email) = ?', [$email])
            ->whereIn('role', $accountType === 'staff' ? ['admin', 'staff'] : ['customer'])
            ->first();
    }

    private function deliver(User $user, Notification $notification): bool
    {
        try {
            if ($this->logsMail(config('auth.mailer'))) {
                throw new \RuntimeException('Auth mail cannot use a log transport.');
            }
            $user->notify($notification);

            return true;
        } catch (Throwable) {
            Log::warning('auth.email_delivery_failed', ['user_id' => $user->id, 'notification' => class_basename($notification)]);

            return false;
        }
    }

    private function logsMail(string $mailer, array $visited = []): bool
    {
        if (in_array($mailer, $visited, true)) {
            return true;
        }
        $settings = config('mail.mailers.'.$mailer, []);
        if (($settings['transport'] ?? null) === 'log') {
            return true;
        }
        foreach ($settings['mailers'] ?? [] as $child) {
            if ($this->logsMail($child, [...$visited, $mailer])) {
                return true;
            }
        }

        return false;
    }

    private function audit(string $action, User $user): void
    {
        Log::info('auth.'.$action, ['user_id' => $user->id, 'role' => $user->role]);
        if (in_array($user->role, ['admin', 'staff'], true)
            && in_array($action, ['password_changed', 'password_reset', 'email_changed', 'email_verified'], true)) {
            DB::table('staff_activity')->insert([
                'actor_id' => $action === 'password_reset' ? null : $user->id,
                'staff_id' => $user->id,
                'action' => $action,
                'before' => null,
                'after' => json_encode($user->fresh()->only(['name', 'email', 'phone', 'avatar_path', 'role', 'is_active', 'permissions']), JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        }
    }
}
