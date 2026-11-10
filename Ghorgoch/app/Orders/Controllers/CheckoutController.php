<?php

namespace App\Orders\Controllers;

use App\Http\Controllers\Controller;
use App\Orders\Domain\Services\CheckoutService;
use App\Orders\Requests\CheckoutRequest;
use App\Orders\Resources\OrderResource;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutService $checkout) {}

    public function review(CheckoutRequest $request): JsonResponse
    {
        return response()->json(['message' => 'Review and confirm these totals before placing your order.', 'data' => $this->checkout->review($request, $request->validated())])->header('Cache-Control', 'no-store, private');
    }

    public function store(CheckoutRequest $request): JsonResponse
    {
        $result = $this->checkout->place($request, $request->validated());
        $data = ['order' => (new OrderResource($result['order']))->resolve($request), 'replayed' => $result['replayed']];
        if ($result['guest_access_token'] !== null) {
            $data['guest_access_token'] = $result['guest_access_token'];
        }

        return response()->json(['message' => 'Order placed.', 'data' => $data], $result['replayed'] ? 200 : 201)->header('Cache-Control', 'no-store, private');
    }
}
