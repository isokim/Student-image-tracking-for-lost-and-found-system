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
use App\Models\LostItem;
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
    public function show(Request $request, Claim $claim): ClaimResource
    {
        $this->authorize('view', $claim);

        $claim->loadMissing([
            // email/admission_number are only exposed to staff - see
            // ClaimResource - so staff can contact both sides.
            'claimant:id,name,email,admission_number', 'reviewer:id,name', 'verifications',
            'foundItem.category:id,slug,name',
            'foundItem.user:id,name,role,email,admission_number', 'foundItem.primaryImage',
            // The claimant's own lost report, when the claim was filed
            // from a match. This is the corroboration staff weigh on the
            // review screen, and without the eager-load ClaimResource's
            // whenLoaded() dropped the key entirely.
            'lostItem.category:id,slug,name',
            'lostItem.user:id,name', 'lostItem.primaryImage',
        ]);

        /**
         * A claim started from the found-items list has no lost report
         * linked, but the claimant may still have reported the item lost.
         * Staff get the claimant's recent lost reports so they can compare
         * them anyway. Never sent to students.
         */
        if ($request->user()->canActAsStaff() && $claim->lost_item_id === null) {
            $claim->setRelation('claimantLostReports', LostItem::query()
                ->where('user_id', $claim->claimant_id)
                ->with(['category:id,slug,name', 'user:id,name', 'primaryImage'])
                ->latest()
                ->limit(5)
                ->get());
        }

        return new ClaimResource($claim);
    }

    public function store(StoreClaimRequest $request): JsonResponse
    {
        $item = FoundItem::findOrFail($request->validated('foundItemId'));

        
        $this->authorize('claim', $item);

        $match = $this->resolveMatch($request->validated('matchId'), $item);

        $claim = $this->service->submit(
            claimant: $request->user(),
            item: $item,
            identifyingInformation: $request->validated('identifyingInformation'),
            verificationAnswers: $request->validated('verificationAnswers') ?? [],
            match: $match,
            lostItem: $this->resolveLostItem($request->validated('lostItemId'), $match),
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

    /**
     * The claimant's own lost report picked on the claim form.
     *
     * StoreClaimRequest already restricted lostItemId to an open report
     * owned by the caller, so this only resolves the model and checks it
     * agrees with the match, when both are given. A claim filed from a
     * match for one report but naming a different report would leave
     * staff comparing against the wrong evidence.
     */
    private function resolveLostItem(int|string|null $lostItemId, ?ItemMatch $match): ?LostItem
    {
        if ($lostItemId === null) {
            return null;
        }

        $lostItem = LostItem::findOrFail($lostItemId);

        if ($match !== null && (string) $match->lost_item_id !== (string) $lostItem->id) {
            throw ValidationException::withMessages([
                'lostItemId' => 'That lost report is not the one this match was suggested for.',
            ]);
        }

        return $lostItem;
    }

    /** POST /api/claims/{claim}/approve - staff only. */
    public function approve(ReviewClaimRequest $request, Claim $claim): ClaimResource
    {
        
        $this->authorize('approve', $claim);

        $claim = $this->service->approve($claim, $request->user(), $request->validated('notes'));

        return new ClaimResource($claim->load([
            'claimant:id,name', 'reviewer:id,name', 'verifications',
            'foundItem.category:id,slug,name',
            'foundItem.user:id,name,role', 'foundItem.primaryImage',
           
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
            
            'lostItem.category:id,slug,name',
            'lostItem.user:id,name', 'lostItem.primaryImage',
        ]));
    }

    public function markReturned(Request $request, Claim $claim): ClaimResource
    {
        $this->authorize('complete', $claim);

        $claim = $this->service->complete($claim, $request->user());

        return new ClaimResource($claim->load([
            'claimant:id,name', 'reviewer:id,name', 'verifications',
            'foundItem.category:id,slug,name',
            'foundItem.user:id,name,role', 'foundItem.primaryImage',
        
            'lostItem.user:id,name', 'lostItem.primaryImage',
        ]));
    }
}
