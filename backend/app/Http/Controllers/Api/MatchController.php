<?php

namespace App\Http\Controllers\Api;

use App\Enums\LostItemStatus;
use App\Enums\MatchStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ItemMatchResource;
use App\Models\ItemMatch;
use App\Services\AuditLogger;
use App\Services\ItemStatusRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * GET /api/matches
     *
     * A student's own suggestions, strongest first. Scoped through their
     * lost reports - a match belongs to whoever filed the report, so
     * there is no query here that could return someone else's.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ItemMatch::class);

        $user = $request->user();

        $matches = ItemMatch::query()
            ->withApiRelations()
            ->visible()
            ->whereHas('lostItem', fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('overall_score')
            ->limit(100)
            ->get();

        return response()->json(ItemMatchResource::collection($matches));
    }

    public function show(ItemMatch $match): ItemMatchResource
    {
        $this->authorize('view', $match);

        $match->loadMissing([
            'lostItem.category:id,slug,name',
            'lostItem.user:id,name', 'lostItem.primaryImage',
            'foundItem.category:id,slug,name',
            'foundItem.user:id,name,role', 'foundItem.primaryImage',
        ]);

        /**
         * Opening a suggestion marks it seen, so the student is not
         * nagged about it again. Only a pending match is touched - a
         * dismissed or claimed one keeps its status.
         */
        if ($match->status === MatchStatus::PendingReview) {
            $match->forceFill(['updated_at' => now()])->save();
        }

        return new ItemMatchResource($match);
    }

    /**
     * POST /api/matches/{match}/dismiss - "that is not my item".
     *
     * Only the student the match belongs to can say this; staff cannot
     * dismiss someone else's suggestion on their behalf.
     */
    public function dismiss(Request $request, ItemMatch $match, ItemStatusRecorder $status): ItemMatchResource
    {
        $this->authorize('dismiss', $match);

        $match->status = MatchStatus::Dismissed;
        $match->dismissed_at = now();
        $match->save();

        /**
         * Once the student has rejected every suggestion, the report is
         * no longer "matched". Without this it kept its "Possible match"
         * badge forever, even after the student said none of them were
         * theirs.
         */
        $lost = $match->lostItem;

        if ($lost?->status === LostItemStatus::Matched
            && ! ItemMatch::where('lost_item_id', $lost->id)->visible()->exists()) {
            $status->transition($lost, LostItemStatus::Active, $request->user(), 'All suggested matches dismissed.');
        }

        $this->audit->log(
            'match.dismissed',
            $match,
            'Student dismissed a suggested match.',
            $request->user(),
        );

        return new ItemMatchResource($match->loadMissing([
            'lostItem.category:id,slug,name',
            'lostItem.user:id,name', 'lostItem.primaryImage',
            'foundItem.category:id,slug,name',
            'foundItem.user:id,name,role', 'foundItem.primaryImage',
        ]));
    }
}
