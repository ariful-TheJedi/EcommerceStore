<?php

namespace App\Users\Controllers;

use App\Http\Controllers\Controller;
use App\Users\Domain\Services\StaffService;
use App\Users\Models\User;
use App\Users\Requests\StaffRequest;
use App\Users\Resources\StaffResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StaffController extends Controller
{
    public function __construct(private readonly StaffService $service) {}

    public function index(StaffRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = User::whereIn('role', ['admin', 'staff'])
            ->when(isset($data['role']), fn (Builder $query) => $query->where('role', $data['role']))
            ->when(isset($data['status']), fn (Builder $query) => $query->where('is_active', $data['status'] === 'active'));
        if (isset($data['search'])) {
            $search = '%'.strtolower($data['search']).'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->whereRaw('LOWER(name) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$search])
                    ->orWhere('phone', 'like', $search);
            });
        }
        $page = $query->orderBy('id')->paginate($data['per_page'] ?? 20);

        return $this->respond([
            'staff' => StaffResource::collection($page->getCollection())->resolve($request),
            'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(StaffRequest $request, int $staff): JsonResponse
    {
        return $this->account($request, $this->staff($staff));
    }

    public function me(StaffRequest $request): JsonResponse
    {
        return $this->account($request, $request->user());
    }

    public function store(StaffRequest $request): JsonResponse
    {
        return $this->account($request, $this->service->create($request->user(), $request->validated()), 201);
    }

    public function update(StaffRequest $request, int $staff): JsonResponse
    {
        return $this->account($request, $this->service->update($request->user(), $staff, $request->validated()));
    }

    public function updateMe(StaffRequest $request): JsonResponse
    {
        return $this->account($request, $this->service->update($request->user(), $request->user()->id, $request->validated(), true));
    }

    public function disable(StaffRequest $request, int $staff): JsonResponse
    {
        return $this->account($request, $this->service->update($request->user(), $staff, ['is_active' => false]));
    }

    public function reactivate(StaffRequest $request, int $staff): JsonResponse
    {
        return $this->account($request, $this->service->update($request->user(), $staff, ['is_active' => true]));
    }

    public function capabilities(StaffRequest $request): JsonResponse
    {
        return $this->respond(['roles' => ['admin', 'staff'], 'permissions' => config('staff.permissions')]);
    }

    public function activity(StaffRequest $request, int $staff): JsonResponse
    {
        $this->staff($staff);
        $page = DB::table('staff_activity')->where('staff_id', $staff)->orderByDesc('id')->paginate($request->integer('per_page', 20));
        $items = $page->getCollection()->map(function (object $entry): object {
            $entry->before = $entry->before === null ? null : json_decode($entry->before, true);
            $entry->after = json_decode($entry->after, true);

            return $entry;
        });

        return $this->respond(['activity' => $items, 'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    private function staff(int $id): User
    {
        return User::whereIn('role', ['admin', 'staff'])->findOrFail($id);
    }

    private function account(StaffRequest $request, User $user, int $status = 200): JsonResponse
    {
        return $this->respond(['user' => (new StaffResource($user))->resolve($request)], $status);
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['message' => 'Success.', 'data' => $data], $status)->header('Cache-Control', 'no-store, private');
    }
}
