<?php

namespace App\Payments\Controllers;

use App\Customers\Domain\Services\CustomerService;
use App\Customers\Requests\CustomerHistoryRequest;
use App\Http\Controllers\Controller;
use App\Orders\Models\Order;
use App\Payments\Models\Payment;
use Illuminate\Http\JsonResponse;

class CustomerPaymentController extends Controller
{
    public function index(CustomerHistoryRequest $request): JsonResponse
    {
        $customer = $request->route('customer');
        $profile = app(CustomerService::class)->resolve($request->user(), $customer === null ? null : (int) $customer);
        $page = Payment::whereIn('order_id', Order::select('id')->where('customer_id', $profile->id))
            ->orderByDesc('id')->paginate($request->integer('per_page', 15), [
                'id', 'order_id', 'provider', 'amount', 'currency', 'status', 'paid_at',
            ]);

        return response()->json(['message' => 'Success.', 'data' => [
            'payments' => $page->items(),
            'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]])->header('Cache-Control', 'no-store, private');
    }
}
