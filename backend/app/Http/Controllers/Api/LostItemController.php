<?php

namespace App\Http\Controllers\Api;

use App\Enums\LostItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Items\StoreLostItemRequest;
use App\Http\Requests\Items\UpdateLostItemRequest;
use App\Http\Resources\ItemMatchResource;
use App\Http\Resources\LostItemResource;
use App\Models\ItemMatch;
use App\Models\LostItem;
use App\Services\LostItemService;
use App\Services\Matching\MatchFinderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Lost reports.
 *
 * Thin by design: validate (form request), authorise (policy), delegate
 * (service), render (resource). Anything that looks like a business rule
 * belongs in LostItemService, not here.
 */
class LostItemController extends Controller
{
    public function __construct(private readonly LostItemService $service)
    {
    }

    /**
     * GET /api/lost-items
     *
     * The board: every open lost report, so someone who picked something
     * up can find its owner. Private detail is stripped per-viewer by
     * LostItemResource.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LostItem::class);

        $items = LostItem::query()
            ->withApiRelations()
            /**
             * Validated against the enum, and cast to a plain string.
             *
             * Any value used to be accepted, which let a student list
             * resolved/closed reports the default scope hides - and
             * $request->string() returns a Stringable object, not a
             * string, which is a fragile thing to hand a query binding.
             */
            ->when(
                $this->validStatus($request, LostItemStatus::class),
                fn ($q, $status) => $q->where('status', $status),
                fn ($q) => $q->open(),
            )
            ->latest('lost_at')
            ->paginate(15);

        return $this->paginated($items, LostItemResource::class);
    }

    /**
     * GET /api/lost-items/mine
     *
     * Scoped to the caller at the query, not filtered afterwards, so
     * there is no path by which another user's report could be included.
     */
    public function mine(Request $request): JsonResponse
    {
        $items = LostItem::query()
            ->withApiRelations()
            ->ownedBy($request->user())
            ->latest('created_at')
            ->get();

        return response()->json(LostItemResource::collection($items));
    }

    /**
     * GET /api/lost-items/{lostItem}
     *
     * The endpoint the brief calls out. Changing the id shows you the
     * public report; it does not show you another student's private
     * notes, because LostItemResource consults viewPrivateDetails().
     */
    public function show(LostItem $lostItem): LostItemResource
    {
        $this->authorize('view', $lostItem);

        return new LostItemResource($lostItem->loadMissing([
            'user:id,name', 'category:id,slug,name', 'primaryImage',
        ]));
    }

    public function store(StoreLostItemRequest $request): JsonResponse
    {
        $item = $this->service->create(
            $request->user(),
            $request->validated(),
            $request->file('photo'),
        );

        return (new LostItemResource($item->load([
            'user:id,name', 'category:id,slug,name', 'primaryImage',
        ])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateLostItemRequest $request, LostItem $lostItem): LostItemResource
    {
        $this->authorize('update', $lostItem);

        $item = $this->service->update($lostItem, $request->validated(), $request->user());

        return new LostItemResource($item->load([
            'user:id,name', 'category:id,slug,name', 'primaryImage',
        ]));
    }

    /**
     * POST /api/lost-items/{lostItem}/close
     *
     * The owner marking a report finished - either they gave up, or they
     * got the item back outside the system.
     */
    public function close(Request $request, LostItem $lostItem): LostItemResource
    {
        $this->authorize('close', $lostItem);

        $item = $this->service->close(
            $lostItem,
            $request->user(),
            resolved: $request->boolean('resolved'),
        );

        return new LostItemResource($item->load([
            'user:id,name', 'category:id,slug,name', 'primaryImage',
        ]));
    }

    /**
     * POST /api/lost-items/{lostItem}/find-matches
     *
     * Runs matching from an existing lost report. This lets the student
     * use the photo and details they already submitted instead of
     * uploading the same image again on the search screen.
     */
    public function findMatches(Request $request, LostItem $lostItem, MatchFinderService $finder): JsonResponse
    {
        $this->authorize('update', $lostItem);

        $lostItem->loadMissing(['category', 'user', 'embedding']);
        $finder->findForLostItem($lostItem);

        $matches = ItemMatch::query()
            ->withApiRelations()
            ->visible()
            ->where('lost_item_id', $lostItem->id)
            ->orderByDesc('overall_score')
            ->limit(20)
            ->get();

        return response()->json(ItemMatchResource::collection($matches));
    }

    public function destroy(Request $request, LostItem $lostItem): JsonResponse
    {
        $this->authorize('delete', $lostItem);

        $this->service->delete($lostItem, $request->user());

        return $this->message('Report deleted.');
    }
}
