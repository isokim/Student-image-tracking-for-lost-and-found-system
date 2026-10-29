<?php

namespace App\Policies;

use App\Enums\ClaimStatus;
use App\Enums\FoundItemStatus;
use App\Models\FoundItem;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what with a found item.
 *
 * The important asymmetry versus lost reports: a found item is only
 * browsable once staff have verified it exists. Before that it is
 * visible to the person who handed it in, and to staff. Otherwise
 * anyone could post "I found an iPhone" and immediately see who comes
 * looking for one - or browse a list of unchecked, possibly fabricated
 * items.
 *
 * private_verification_info is never browsable at all. It is the
 * evidence a claim is checked against, so it is restricted to the
 * finder and staff by viewPrivateDetails().
 */
class FoundItemPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        /**
         * Admins bypass the viewing and workflow rules - but NOT claim().
         *
         * The claim rules exist to prevent self-dealing: whoever handed an
         * item in cannot then claim it back. That reasoning applies to an
         * administrator exactly as much as to a student, so the bypass is
         * switched off for that one ability rather than silently granting
         * admins a hole in the rule.
         */
        if ($ability === 'claim') {
            return null;
        }

        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, FoundItem $foundItem): Response
    {
        if ($foundItem->status->isPubliclyVisible()) {
            return Response::allow();
        }

        if ($this->owns($user, $foundItem) || $user->canActAsStaff()) {
            return Response::allow();
        }

        /**
         * SECURITY: 404, not 403.
         *
         * A 403 would confirm that found item #25 exists and is simply
         * awaiting verification. Since the caller has no legitimate way
         * to know it exists at all, the honest answer is "no such
         * record". This is what stops someone enumerating ids to learn
         * what has been handed in.
         */
        return Response::denyAsNotFound();
    }

    /**
     * Field-level: who may read private_verification_info.
     *
     * Not the claimant, and not a student browsing the item - if the
     * secret detail were visible, anyone could recite it and claim the
     * item. Only the finder and staff reviewing the claim.
     */
    public function viewPrivateDetails(User $user, FoundItem $foundItem): bool
    {
        return $this->owns($user, $foundItem) || $user->canActAsStaff();
    }

    public function create(User $user): bool
    {
        // Students and staff can both hand an item in.
        return true;
    }

    /**
     * Whether this user may open a claim against this item.
     *
     * Lives here rather than in ClaimPolicy because Laravel resolves the
     * policy from the model handed to the Gate, and the model in question
     * is the found item.
     */
    public function claim(User $user, FoundItem $foundItem): Response
    {
        if (! $foundItem->status->isClaimable()) {
            return Response::deny('This item is not available to claim.');
        }

        // Whoever handed the item in cannot claim it back.
        if ($this->owns($user, $foundItem)) {
            return Response::deny('You cannot claim an item you reported as found.');
        }

        // One open claim per person per item.
        $alreadyClaimed = $foundItem->claims()
            ->where('claimant_id', $user->id)
            ->whereIn('status', [
                ClaimStatus::Draft,
                ClaimStatus::PendingReview,
                ClaimStatus::Approved,
            ])
            ->exists();

        if ($alreadyClaimed) {
            return Response::deny('You already have a claim open on this item.');
        }

        return Response::allow();
    }

    public function update(User $user, FoundItem $foundItem): Response
    {
        if ($user->canActAsStaff()) {
            return Response::allow();
        }

        if (! $this->owns($user, $foundItem)) {
            return Response::deny('You can only edit items you handed in.');
        }

        // Once staff have signed it off, the description backing a claim
        // must not shift under them.
        if ($foundItem->status !== FoundItemStatus::PendingVerification) {
            return Response::deny('This item has already been reviewed and can no longer be edited.');
        }

        return Response::allow();
    }

    /**
     * Staff sign-off. A student cannot verify an item - including their
     * own - or they could self-approve a fabricated item into the
     * searchable list.
     */
    public function verify(User $user, FoundItem $foundItem): Response
    {
        if (! $user->canActAsStaff()) {
            return Response::deny('Only staff can verify found items.');
        }

        if ($foundItem->status !== FoundItemStatus::PendingVerification) {
            return Response::deny('This item has already been reviewed.');
        }

        return Response::allow();
    }

    public function reject(User $user, FoundItem $foundItem): Response
    {
        return $this->verify($user, $foundItem);
    }

    /** Marking an item physically handed back to its owner. */
    public function markReturned(User $user, FoundItem $foundItem): Response
    {
        return $user->canActAsStaff()
            ? Response::allow()
            : Response::deny('Only staff can record a return.');
    }

    public function delete(User $user, FoundItem $foundItem): Response
    {
        return $this->owns($user, $foundItem) && $foundItem->status === FoundItemStatus::PendingVerification
            ? Response::allow()
            : Response::deny('You cannot delete this item.');
    }

    private function owns(User $user, FoundItem $foundItem): bool
    {
        return $foundItem->user_id === $user->id;
    }
}
