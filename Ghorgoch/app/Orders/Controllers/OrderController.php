<?php

namespace App\Orders\Controllers;

use App\Http\Controllers\Controller;
use App\Orders\Domain\Services\OrderReportService;
use App\Orders\Domain\Services\OrderService;
use App\Orders\Models\Order;
use App\Orders\Requests\OrderRequest;
use App\Orders\Resources\OrderResource;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders, private readonly OrderReportService $reports) {}

    public function index(OrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = $this->reports->query($data)->withSum('items', 'quantity');
        if ($data['deletion_list'] ?? false) {
            $query->with('items');
        }
        $page = $query->orderByDesc('id')->paginate($data['per_page'] ?? 15);

        return $this->respond(['orders' => OrderResource::collection($page->getCollection())->resolve($request),
            'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function show(OrderRequest $request): JsonResponse
    {
        return $this->account($request, $this->orders->find($request));
    }

    public function store(OrderRequest $request): JsonResponse
    {
        return $this->account($request, $this->orders->createManual($request, $request->validated()));
    }

    public function destroy(OrderRequest $request): JsonResponse
    {
        $this->orders->delete($request, $this->orders->find($request), $request->validated('reason'));

        return $this->respond(['deleted' => true]);
    }

    public function bulkDestroy(OrderRequest $request): JsonResponse
    {
        $count = $this->orders->deleteMultiple($request, $request->validated('order_ids'), $request->validated('reason'));

        return $this->respond(['deleted_count' => $count]);
    }

    public function report(OrderRequest $request): JsonResponse
    {
        return $this->respond($this->reports->summary($request->validated()));
    }

    public function finances(OrderRequest $request): JsonResponse
    {
        return $this->account($request, $this->orders->finances($request, $this->orders->find($request), $request->validated()));
    }

    public function cancel(OrderRequest $request): JsonResponse
    {
        return $this->account($request, $this->orders->transition($request, $this->orders->find($request), ['status' => 'cancelled', ...$request->validated()]));
    }

    public function transition(OrderRequest $request): JsonResponse
    {
        return $this->account($request, $this->orders->transition($request, $this->orders->find($request), $request->validated()));
    }

    public function delivery(OrderRequest $request): JsonResponse
    {
        return $this->account($request, $this->orders->delivery($request, $this->orders->find($request), $request->validated()));
    }

    public function address(OrderRequest $request): JsonResponse
    {
        return $this->account($request, $this->orders->address($request, $this->orders->find($request), $request->validated('delivery'), $request->validated('note')));
    }

    public function note(OrderRequest $request): JsonResponse
    {
        return $this->account($request, $this->orders->note($request, $this->orders->find($request), $request->validated('note')));
    }

    private function account(OrderRequest $request, Order $order): JsonResponse
    {
        return $this->respond(['order' => (new OrderResource($order))->resolve($request)]);
    }

    private function respond(array $data): JsonResponse
    {
        return response()->json(['message' => 'Success.', 'data' => $data])->header('Cache-Control', 'no-store, private');
    }
}
