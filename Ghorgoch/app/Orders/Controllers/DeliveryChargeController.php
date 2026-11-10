<?php

namespace App\Orders\Controllers;

use App\Http\Controllers\Controller;
use App\Orders\Domain\Services\DeliveryChargeService;
use App\Orders\Requests\DeliveryChargeRequest;
use Illuminate\Http\JsonResponse;

class DeliveryChargeController extends Controller
{
    public function __construct(private readonly DeliveryChargeService $charges) {}

    public function publicShow(): JsonResponse
    {
        return $this->respond($this->charges->rates());
    }

    public function show(DeliveryChargeRequest $request): JsonResponse
    {
        return $this->respond($this->charges->rates());
    }

    public function update(DeliveryChargeRequest $request): JsonResponse
    {
        return $this->respond($this->charges->update($request->user(), $request->validated()));
    }

    /** @param array<string, mixed> $data */
    private function respond(array $data): JsonResponse
    {
        return response()->json(['message' => 'Success.', 'data' => $data])->header('Cache-Control', 'no-store, private');
    }
}
