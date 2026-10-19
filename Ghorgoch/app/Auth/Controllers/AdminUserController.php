<?php

namespace App\Auth\Controllers;

use App\Auth\Domain\Services\UserProvisioning;
use App\Auth\Requests\AuthRequest;
use App\Auth\Resources\AccountResource;
use App\Http\Controllers\Controller;
use App\Users\Domain\Services\StaffService;
use Illuminate\Http\JsonResponse;

class AdminUserController extends Controller
{
    public function __construct(private readonly UserProvisioning $users) {}

    public function store(AuthRequest $request, StaffService $staff): JsonResponse
    {
        $data = $request->validated();
        $user = $data['role'] === 'customer'
            ? $this->users->create($data)
            : $staff->create($request->user(), $data);

        return response()->json([
            'message' => 'User created.',
            'data' => ['user' => (new AccountResource($user))->resolve($request)],
        ], 201)->header('Cache-Control', 'no-store, private');
    }
}
