<?php

namespace App\Auth\Controllers;

use App\Auth\Domain\Services\AuthService;
use App\Auth\Requests\AuthRequest;
use App\Auth\Resources\AccountResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $service) {}

    public function csrf(AuthRequest $request): JsonResponse
    {
        return $this->respond('CSRF token issued.', ['csrf_token' => $request->session()->token()]);
    }

    public function login(AuthRequest $request): JsonResponse
    {
        $user = $this->service->login($request, $this->accountType($request), $request->validated());

        return $this->respond('Logged in.', [
            'user' => (new AccountResource($user))->resolve($request),
            'csrf_token' => $request->session()->token(),
        ]);
    }

    public function me(AuthRequest $request): JsonResponse
    {
        return $this->respond('Authenticated account.', ['user' => (new AccountResource($request->user()))->resolve($request)]);
    }

    public function logout(AuthRequest $request): JsonResponse
    {
        $this->service->logout($request, $this->accountType($request));

        return $this->respond('Logged out.');
    }

    public function logoutAll(AuthRequest $request): JsonResponse
    {
        $this->service->logout($request, $this->accountType($request), true);

        return $this->respond('Logged out from all devices.');
    }

    public function changePassword(AuthRequest $request): JsonResponse
    {
        $this->service->changePassword($request->user(), $request->validated());
        $this->service->logout($request, $this->accountType($request));

        return $this->respond('Password changed. Log in again.');
    }

    public function forgotPassword(AuthRequest $request): JsonResponse
    {
        $this->service->forgotPassword($this->accountType($request), $request->validated('email'));

        return $this->respond('If an eligible account exists, password reset instructions will be sent.');
    }

    public function changeEmail(AuthRequest $request): JsonResponse
    {
        $user = $this->service->changeEmail($request->user(), $request->validated());
        $this->service->logout($request, $this->accountType($request));
        $sent = $this->service->sendVerification($user);

        return $this->respond('Email changed. Log in with the new email and verify it.', ['verification_sent' => $sent]);
    }

    public function resetPassword(AuthRequest $request): JsonResponse
    {
        $this->service->resetPassword($this->accountType($request), $request->validated());

        return $this->respond('Password reset. Log in again.');
    }

    public function resendVerification(AuthRequest $request): JsonResponse
    {
        if (! $this->service->sendVerification($request->user())) {
            return $this->respond('Email delivery is unavailable. Please try again later.', [], 503);
        }

        return $this->respond('If verification is needed, instructions have been sent.');
    }

    public function verifyEmail(AuthRequest $request): JsonResponse
    {
        $this->service->verifyEmail($request->user(), $request->validated('token'));

        return $this->respond('Email verified.');
    }

    private function accountType(AuthRequest $request): string
    {
        return $request->route()->defaults['account_type'];
    }

    private function respond(string $message, array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => $data], $status)
            ->header('Cache-Control', 'no-store, private');
    }
}
