<?php

namespace App\Policies;

use App\Models\ItemMatch;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * A match pairs someone's lost report with a found item, so it is
 * private to the student who filed that report. Seeing another
 * student's matches would reveal both what they lost and what the
 * system thinks they are about to claim.
 */
class ItemMatchPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        // The controller scopes the query to the caller's own matches.
        return true;
    }

    public function view(User $user, ItemMatch $match): Response
    {
        if ($match->belongsToStudent($user) || $user->canActAsStaff()) {
            return Response::allow();
        }

        // 404 rather than 403: the caller has no way to know this match
        // exists, so confirming it would leak information.
        return Response::denyAsNotFound();
    }

    public function dismiss(User $user, ItemMatch $match): Response
    {
        // Only the student it belongs to can say "that is not my item".
        // Staff have no business dismissing someone else's suggestion.
        return $match->belongsToStudent($user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
