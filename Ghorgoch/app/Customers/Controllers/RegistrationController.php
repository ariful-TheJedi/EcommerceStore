<?php

namespace App\Customers\Controllers;

use App\Auth\Domain\Services\AuthService;
use App\Auth\Resources\AccountResource;
use App\Customers\Domain\Services\RegistrationService;
use App\Customers\Requests\RegistrationRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class RegistrationController extends Controller
{
    public function __construct(private readonly RegistrationService $registration, private readonly AuthService $auth) {}

    public function store(RegistrationRequest $request): JsonResponse
    {
        $user = $this->registration->register($request->validated());
        $sent = $this->auth->sendVerification($user);

        return response()->json([
            'message' => 'Customer registered. Log in to your account.',
            'data' => [
                'user' => (new AccountResource($user))->resolve($request),
                'verification_sent' => $sent,
            ],
        ], 201)->header('Cache-Control', 'no-store, private');
    }
}
