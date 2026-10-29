<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\ClaimStatus;
use App\Enums\FoundItemStatus;
use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\FoundItem;
use Illuminate\Http\JsonResponse;

/**
 * Counts for the staff dashboard.
 *
 * Deliberately returns numbers only, not records. The dashboard needs to
 * say "4 claims waiting"; the actual claims are fetched from the claims
 * endpoint, where the policy runs per record.
 */
class StaffDashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // Second layer alongside the route's role:staff,admin. Seeing
        // the review queue is the staff capability this page reports on.
        $this->authorize('viewAny', Claim::class);

        return response()->json([
            'pendingVerifications' => FoundItem::where('status', FoundItemStatus::PendingVerification)->count(),
            'pendingClaims' => Claim::where('status', ClaimStatus::PendingReview)->count(),
            'approvedAwaitingCollection' => Claim::where('status', ClaimStatus::Approved)->count(),
            'itemsReturnedThisMonth' => Claim::where('status', ClaimStatus::Completed)
                ->where('completed_at', '>=', now()->startOfMonth())
                ->count(),
        ]);
    }
}
