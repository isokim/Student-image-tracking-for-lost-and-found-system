<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ClaimStatus;
use App\Enums\FoundItemStatus;
use App\Enums\LostItemStatus;
use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\FoundItem;
use App\Models\ItemMatch;
use App\Models\LostItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/admin/stats and /api/admin/dashboard.
 *
 * The four headline figures match the SystemStats interface in
 * src/features/admin/adminService.ts; the rest are extra context for
 * the admin dashboard.
 */
class AdminDashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        return response()->json([
            // SystemStats, as the frontend declares it.
            'activeLostReports' => LostItem::whereIn('status', [
                LostItemStatus::Active, LostItemStatus::Matched,
            ])->count(),
            'pendingFoundVerifications' => FoundItem::where('status', FoundItemStatus::PendingVerification)->count(),
            'pendingClaims' => Claim::where('status', ClaimStatus::PendingReview)->count(),
            'itemsReturnedThisMonth' => Claim::where('status', ClaimStatus::Completed)
                ->where('completed_at', '>=', now()->startOfMonth())
                ->count(),

            // Additional context.
            'totalUsers' => User::count(),
            'totalStudents' => User::where('role', \App\Enums\UserRole::Student)->count(),
            'totalStaff' => User::where('role', \App\Enums\UserRole::Staff)->count(),
            'suspendedUsers' => User::where('status', \App\Enums\UserStatus::Suspended)->count(),
            'verifiedFoundItems' => FoundItem::where('status', FoundItemStatus::Verified)->count(),
            'suggestedMatches' => ItemMatch::visible()->count(),
        ]);
    }
}
