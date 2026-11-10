<?php

namespace App\Customers\Controllers;

use App\Customers\Domain\Services\AddressService;
use App\Customers\Domain\Services\CustomerService;
use App\Customers\Models\Customer;
use App\Customers\Requests\AddressRequest;
use App\Customers\Resources\AddressResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AddressController extends Controller
{
    public function __construct(private readonly AddressService $addresses, private readonly CustomerService $customers) {}

    public function index(AddressRequest $request): JsonResponse
    {
        $page = $this->profile($request)->addresses()->orderByDesc('is_default')->orderBy('id')->paginate($request->integer('per_page', 15));

        return $this->respond([
            'addresses' => AddressResource::collection($page->getCollection())->resolve($request),
            'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(AddressRequest $request): JsonResponse
    {
        return $this->respond(['address' => (new AddressResource($this->profile($request)->addresses()->findOrFail((int) $request->route('address'))))->resolve($request)]);
    }

    public function store(AddressRequest $request): JsonResponse
    {
        $address = $this->addresses->save($this->profile($request, true), $request->validated());

        return $this->respond(['address' => (new AddressResource($address))->resolve($request)], 201);
    }

    public function update(AddressRequest $request): JsonResponse
    {
        $address = $this->addresses->save($this->profile($request, true), $request->validated(), (int) $request->route('address'));

        return $this->respond(['address' => (new AddressResource($address))->resolve($request)]);
    }

    public function destroy(AddressRequest $request): JsonResponse
    {
        $this->addresses->delete($this->profile($request, true), (int) $request->route('address'));

        return $this->respond([]);
    }

    private function profile(AddressRequest $request, bool $write = false): Customer
    {
        $customer = $request->route('customer');

        return $this->customers->resolve($request->user(), $customer === null ? null : (int) $customer, $write);
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['message' => 'Success.', 'data' => $data], $status)->header('Cache-Control', 'no-store, private');
    }
}
