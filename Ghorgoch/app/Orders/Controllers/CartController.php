<?php

namespace App\Orders\Controllers;

use App\Http\Controllers\Controller;
use App\Orders\Domain\Services\CartService;
use App\Orders\Requests\CartRequest;
use Illuminate\Http\JsonResponse;

class CartController extends Controller
{
    public function __construct(private readonly CartService $carts) {}

    public function show(CartRequest $request): JsonResponse
    {
        return $this->respond($this->carts->locked($this->carts->resolve($request), fn ($cart): array => $this->carts->summary($cart)));
    }

    public function store(CartRequest $request): JsonResponse
    {
        return $this->respond($this->carts->change($this->carts->resolve($request), $request->integer('variant_id'), $request->integer('quantity'), true));
    }

    public function update(CartRequest $request): JsonResponse
    {
        return $this->respond($this->carts->change($this->carts->resolve($request), (int) $request->route('variant'), $request->integer('quantity'), false));
    }

    public function destroy(CartRequest $request): JsonResponse
    {
        return $this->respond($this->carts->remove($this->carts->resolve($request), (int) $request->route('variant')));
    }

    public function merge(CartRequest $request): JsonResponse
    {
        return $this->respond($this->carts->merge($request));
    }

    public function sync(CartRequest $request): JsonResponse
    {
        return $this->respond($this->carts->sync($this->carts->resolve($request), $request->validated('items')));
    }

    private function respond(array $cart): JsonResponse
    {
        return response()->json(['message' => 'Success.', 'data' => ['cart' => $cart]])->header('Cache-Control', 'no-store, private');
    }
}
