<?php

namespace App\Http\Controllers\Api;

use App\Enums\ClaimStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Claims\ReviewClaimRequest;
use App\Http\Requests\Claims\StoreClaimRequest;
use App\Http\Resources\ClaimResource;
use App\Models\Claim;
use App\Models\FoundItem;
use App\Models\ItemMatch;
use App\Services\ClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class ClaimController extends Controller
{
    public function __construct(private readonly ClaimService $service)
    {
    }

    /**
     * GET /api/claims
     *
     * A student sees their own claims; staff see the review queue.
     *
     * The distinction is made in the QUERY, not by filtering results
     * afterwards. That way there is no code path where another student's
     * claim is loaded and then dropped - it is never fetched at all.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Claim::class);

        $query = Claim::query()->withApiRelations();

        if ($request->user()->canActAsStaff()) {
            $query->when(
                $this->validStatus($request, ClaimStatus::class),
                fn ($q, $status) => $q->where('status', $status),
            );
        } else {
            $query->ownedBy($request->user());
        }

        return response()->json(
            ClaimResource::collection($query->latest()->limit(100)->get())
        );
    }

    /**
     * GET /api/claims/{claim}
     *
     * Another student's claim returns 404, not 403 - a 403 would confirm
     * the claim exists, which is itself information about who is
     * claiming what.
     */
    public function show(Claim $claim): ClaimResource
    {
        $this->authorize('view', $claim);

        return new ClaimResource($claim->loadMissing([
            'claimant:id,name', 'reviewer:id,name', 'verifications',
            'foundItem.category:id,slug,name',
            'foundItem.user:id,name,role', 'foundItem.primaryImage',
            // The claimant's own lost report, when the claim was filed
            // from a match. This is the corroboration staff weigh on the
            // review screen, and without the eager-load ClaimResource's
            // whenLoaded() dropped the key entirely.
            'lostItem.category:id,slug,name',
            'lostItem.user:id,name', 'lostItem.primaryImage',
        ]));
    }

    public function store(StoreClaimRequest $request): JsonResponse
    {
        $item = FoundItem::findOrFail($request->validated('foundItemId'));

        /**
         * The second authorisation check, on the specific item.
         *
         * StoreClaimRequest::authorize() only established that this user
         * may file claims at all. This is what enforces that the item is
         * verified, that the finder is not claiming their own item, and
         * that they have not already got a claim open on it.
         */
        $this->authorize('claim', $item);

        $claim = $this->service->submit(
            claimant: $request->user(),
            item: $item,
            identifyingInformation: $request->validated('identifyingInformation'),
            verificationAnswers: $request->validated('verificationAnswers'),
            match: $this->resolveMatch($request->validated('matchId'), $item),
        );

        return (new ClaimResource($claim->load([
            'claimant:id,name', 'verifications',
            'foundItem.category:id,slug,name',
            'foundItem.user:id,name,role', 'foundItem.primaryImage',
        ])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Validates the optional AI match a claim is filed from.
     *
     * SECURITY: `matchId` arrives from the client, and validating only
     * that the row exists is not enough. A match belongs to whoever filed
     * the lost report, so an unchecked id lets a student attach someone
     * else's match to their own claim. That would:
     *
     *   - forge the "this student reported losing exactly this last week"
     *     corroboration that staff weigh when approving, and
     *   - on completion, mark the victim's genuine lost report resolved,
     *     silently removing a real missing item from future matching.
     *
     * Two checks, therefore: the caller must be allowed to see the match
     * (404 otherwise, so ids cannot be probed), and it must actually
     * point at the item being claimed.
     */
    private function resolveMatch(?string $matchId, FoundItem $item): ?ItemMatch
    {
        if ($matchId === null) {
            return null;
        }

        $match = ItemMatch::findOrFail($matchId);

        // ItemMatchPolicy denies as 404 for anyone but the owning student.
        $this->authorize('view', $match);

        if ($match->found_item_id !== $item->id) {
            throw ValidationException::withMessages([
                'matchId' => 'That suggested match is not for this item.',
            ]);
        }

        return $match;
    }

    /** POST /api/claims/{claim}/approve - staff only. */
    public function approve(ReviewClaimRequest $request, Claim $claim): ClaimResource
    {
        // 'approve', not 'review': it additionally refuses once another
        // claim on the same item has already been approved.
        $this->authorize('approve', $claim);

        $claim = $this->service->approve($claim, $request->user(), $request->validated('notes'));

        return new ClaimResource($claim->load([
            'claimant:id,name', 'reviewer:id,name', 'verifications',
            'foundItem.category:id,slug,name',
            'foundItem.user:id,name,role', 'foundItem.primaryImage',
            // The claimant's own lost report, when the claim was filed
            // from a match. This is the corroboration staff weigh on the
            // review screen, and without the eager-load ClaimResource's
            // whenLoaded() dropped the key entirely.
            'lostItem.category:id,slug,name',
            'lostItem.user:id,name', 'lostItem.primaryImage',
        ]));
    }

    public function reject(ReviewClaimRequest $request, Claim $claim): ClaimResource
    {
        $this->authorize('review', $claim);

        $claim = $this->service->reject($claim, $request->user(), $request->validated('notes'));

        return new ClaimResource($claim->load([
            'claimant:id,name', 'reviewer:id,name', 'verifications',
            'foundItem.category:id,slug,name',
            'foundItem.user:id,name,role', 'foundItem.primaryImage',
            // The claimant's own lost report, when the claim was filed
            // from a match. This is the corroboration staff weigh on the
            // review screen, and without the eager-load ClaimResource's
            // whenLoaded() dropped the key entirely.
            'lostItem.category:id,slug,name',
            'lostItem.user:id,name', 'lostItem.primaryImage',
        ]));
    }

    /**
     * POST /api/claims/{claim}/return
     *
     * Records that the student physically collected the item. Separate
     * from approval because approving is a decision and collecting is an
     * event - and an approved claim that is never collected matters.
     */
    public function markReturned(Request $request, Claim $claim): ClaimResource
    {
        $this->authorize('complete', $claim);

        $claim = $this->service->complete($claim, $request->user());

        return new ClaimResource($claim->load([
            'claimant:id,name', 'reviewer:id,name', 'verifications',
            'foundItem.category:id,slug,name',
            'foundItem.user:id,name,role', 'foundItem.primaryImage',
            // The claimant's own lost report, when the claim was filed
            // from a match. This is the corroboration staff weigh on the
            // review screen, and without the eager-load ClaimResource's
            // whenLoaded() dropped the key entirely.
            'lostItem.category:id,slug,name',
            'lostItem.user:id,name', 'lostItem.primaryImage',
        ]));
    }
}
