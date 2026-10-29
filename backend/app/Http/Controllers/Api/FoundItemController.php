<?php

namespace App\Http\Controllers\Api;

use App\Enums\FoundItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Items\RejectFoundItemRequest;
use App\Http\Requests\Items\StoreFoundItemRequest;
use App\Http\Requests\Items\UpdateFoundItemRequest;
use App\Http\Resources\FoundItemResource;
use App\Models\FoundItem;
use App\Services\FoundItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FoundItemController extends Controller
{
    private const EAGER = ['user:id,name,role', 'verifier:id,name', 'category:id,slug,name', 'primaryImage'];

    public function __construct(private readonly FoundItemService $service)
    {
    }

    /**
     * GET /api/found-items
     *
     * SECURITY: students see only verified items. An unverified report
     * may describe an object nobody has confirmed exists, and listing
     * them would let anyone browse everything handed in today - useful
     * to someone hoping to claim an item that is not theirs.
     *
     * Staff get the full list, because triaging it is their job.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FoundItem::class);

        $query = FoundItem::query()->withApiRelations();

        if ($request->user()->canActAsStaff()) {
            $query->when(
                $this->validStatus($request, FoundItemStatus::class),
                fn ($q, $status) => $q->where('status', $status),
            );
        } else {
            $query->publiclyVisible();
        }

        return $this->paginated($query->latest('found_at')->paginate(15), FoundItemResource::class);
    }

    /** GET /api/found-items/mine - items this user handed in. */
    public function mine(Request $request): JsonResponse
    {
        $items = FoundItem::query()
            ->withApiRelations()
            ->ownedBy($request->user())
            ->latest('created_at')
            ->get();

        return response()->json(FoundItemResource::collection($items));
    }

    /**
     * GET /api/found-items/{foundItem}
     *
     * Returns 404 rather than 403 when a student asks for an unverified
     * item, so ids cannot be probed to learn what has been handed in.
     */
    public function show(FoundItem $foundItem): FoundItemResource
    {
        $this->authorize('view', $foundItem);

        return new FoundItemResource($foundItem->loadMissing(self::EAGER));
    }

    public function store(StoreFoundItemRequest $request): JsonResponse
    {
        $item = $this->service->create(
            $request->user(),
            $request->validated(),
            $request->file('photo'),
        );

        return (new FoundItemResource($item->load(self::EAGER)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateFoundItemRequest $request, FoundItem $foundItem): FoundItemResource
    {
        $this->authorize('update', $foundItem);

        $item = $this->service->update($foundItem, $request->validated(), $request->user());

        return new FoundItemResource($item->load(self::EAGER));
    }

    /**
     * POST /api/found-items/{foundItem}/verify - staff sign-off.
     *
     * This is the gate that makes an item searchable and matchable, so
     * the policy restricts it to staff and refuses a second verification.
     */
    public function verify(Request $request, FoundItem $foundItem): FoundItemResource
    {
        $this->authorize('verify', $foundItem);

        $item = $this->service->verify($foundItem, $request->user());

        return new FoundItemResource($item->load(self::EAGER));
    }

    public function reject(RejectFoundItemRequest $request, FoundItem $foundItem): FoundItemResource
    {
        $this->authorize('reject', $foundItem);

        $item = $this->service->reject($foundItem, $request->user(), $request->validated('reason'));

        return new FoundItemResource($item->load(self::EAGER));
    }

    public function destroy(Request $request, FoundItem $foundItem): JsonResponse
    {
        $this->authorize('delete', $foundItem);

        $this->service->delete($foundItem, $request->user());

        return $this->message('Item deleted.');
    }
}
