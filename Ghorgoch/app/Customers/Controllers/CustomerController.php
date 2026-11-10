<?php

namespace App\Customers\Controllers;

use App\Customers\Domain\Services\CustomerService;
use App\Customers\Models\Customer;
use App\Customers\Requests\CustomerRequest;
use App\Customers\Resources\CustomerResource;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $service) {}

    public function index(CustomerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = Customer::with('user')->whereHas('user', fn (Builder $query) => $query->where('role', 'customer'));
        if (isset($data['search'])) {
            $search = '%'.mb_strtolower($data['search']).'%';
            $query->where(function (Builder $query) use ($search, $data): void {
                if (ctype_digit($data['search'])) {
                    $query->where('id', (int) $data['search']);
                }
                $query->orWhereHas('user', fn (Builder $query) => $query
                    ->whereRaw('LOWER(name) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$search])->orWhere('phone', 'like', $search));
            });
        }
        if (isset($data['status'])) {
            if ($data['status'] === 'closed') {
                $query->whereNotNull('closed_at');
            } else {
                $query->whereNull('closed_at')->whereHas('user', fn (Builder $query) => $query->where('is_active', $data['status'] === 'active'));
            }
        }
        $page = $query->orderBy('id')->paginate($data['per_page'] ?? 15);

        return $this->respond([
            'customers' => CustomerResource::collection($page->getCollection())->resolve($request),
            'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function me(CustomerRequest $request): JsonResponse
    {
        return $this->account($request, $this->service->resolve($request->user()));
    }

    public function updateMe(CustomerRequest $request): JsonResponse
    {
        return $this->account($request, $this->service->update($this->service->resolve($request->user()), $request->validated()));
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        return $this->account($request, $this->service->create($request->user(), $request->validated()), 201);
    }

    public function show(CustomerRequest $request, int $customer): JsonResponse
    {
        return $this->account($request, $this->service->resolve($request->user(), $customer));
    }

    public function update(CustomerRequest $request, int $customer): JsonResponse
    {
        return $this->account($request, $this->service->update($this->service->resolve($request->user(), $customer, true), $request->validated()));
    }

    public function block(CustomerRequest $request, int $customer): JsonResponse
    {
        return $this->changeStatus($request, $customer, true);
    }

    public function unblock(CustomerRequest $request, int $customer): JsonResponse
    {
        return $this->changeStatus($request, $customer, false);
    }

    public function close(CustomerRequest $request): JsonResponse
    {
        $customer = $request->route('customer');
        $customer = $customer === null ? null : (int) $customer;
        $profile = $this->service->resolve($request->user(), $customer, true);
        $this->service->close($profile, $request->user(), $request->validated());

        return $this->respond(['status' => 'closed']);
    }

    public function notes(CustomerRequest $request, int $customer): JsonResponse
    {
        return $this->history($request, $customer, 'customer_notes', 'notes', ['id', 'actor_id', 'body', 'created_at']);
    }

    public function storeNote(CustomerRequest $request, int $customer): JsonResponse
    {
        $profile = $this->service->resolve($request->user(), $customer);
        $id = $this->service->note($profile, $request->user(), $request->validated('body'));

        return $this->respond(['note_id' => $id], 201);
    }

    public function statusHistory(CustomerRequest $request, int $customer): JsonResponse
    {
        return $this->history($request, $customer, 'customer_status_history', 'history', ['id', 'actor_id', 'action', 'reason', 'created_at']);
    }

    private function history(CustomerRequest $request, int $customer, string $table, string $key, array $columns): JsonResponse
    {
        $this->service->resolve($request->user(), $customer);
        $page = DB::table($table)->where('customer_id', $customer)->orderByDesc('id')->paginate($request->integer('per_page', 15), $columns);

        return $this->respond([$key => $page->items(), 'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    private function changeStatus(CustomerRequest $request, int $id, bool $blocked): JsonResponse
    {
        return $this->account($request, $this->service->status(
            $this->service->resolve($request->user(), $id, true), $request->user(), $blocked, $request->validated('reason'),
        ));
    }

    private function account(CustomerRequest $request, Customer $customer, int $status = 200): JsonResponse
    {
        return $this->respond(['customer' => (new CustomerResource($customer))->resolve($request)], $status);
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['message' => 'Success.', 'data' => $data], $status)->header('Cache-Control', 'no-store, private');
    }
}
