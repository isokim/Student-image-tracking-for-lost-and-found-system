<?php

namespace App\Policies;

use App\Enums\ClaimStatus;
use App\Enums\FoundItemStatus;
use App\Models\Claim;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Claims are the most sensitive records in the system: they contain a
 * student's proof-of-ownership answers. Nothing here is browsable.
 */
class ClaimPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        /**
         * Admins bypass the permission rules, but not `approve`.
         *
         * Approving a second claim on an item that is already spoken for
         * is a data-integrity problem, not a permissions one - it tells
         * two students to come and collect the same object. That must not
         * be bypassable by anyone.
         */
        if ($ability === 'approve') {
            return null;
        }

        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        // Allowed, but the controller still scopes the query: a student
        // sees only their own claims, staff see the review queue.
        return true;
    }

    public function view(User $user, Claim $claim): Response
    {
        if ($this->owns($user, $claim) || $user->canActAsStaff()) {
            return Response::allow();
        }

        /**
         * SECURITY: 404, not 403 - the brief's "students cannot access
         * private claim information" rule. A 403 would confirm that claim
         * #25 exists and belongs to someone else, which is itself a leak.
         */
        return Response::denyAsNotFound();
    }

    /**
     * Only reviewing staff may read the expected answers stored against
     * the verification questions. The claimant must never see them - the
     * whole point is that they had to know the answer already.
     */
    public function viewVerificationAnswers(User $user, Claim $claim): bool
    {
        return $user->canActAsStaff();
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * "May I claim THIS item?" lives in FoundItemPolicy::claim(), not
     * here.
     *
     * Laravel picks the policy from the model passed to the Gate, so
     * Gate::allows('claim', $foundItem) resolves FoundItemPolicy. A
     * method of that name on this class would simply never be called.
     */
    public function update(User $user, Claim $claim): Response
    {
        if (! $this->owns($user, $claim)) {
            return Response::denyAsNotFound();
        }

        // Once submitted, the claimant cannot rewrite their answers -
        // otherwise they could revise them after a reviewer hinted at
        // what the right answer was.
        if ($claim->status !== ClaimStatus::Draft) {
            return Response::deny('This claim has been submitted and can no longer be edited.');
        }

        return Response::allow();
    }

    /**
     * Staff decision. Note a staff member may not review their own claim,
     * nor a claim on an item they handed in themselves - both would let
     * one person complete the whole chain unchecked.
     */
    public function review(User $user, Claim $claim): Response
    {
        if (! $user->canActAsStaff()) {
            return Response::deny('Only staff can review claims.');
        }

        if ($this->owns($user, $claim)) {
            return Response::deny('You cannot review your own claim.');
        }

        if (! $claim->status->isReviewable()) {
            return Response::deny('This claim is not awaiting review.');
        }

        return Response::allow();
    }

    /**
     * Approving specifically. Everything review() requires, plus: the
     * item must still be available.
     *
     * Two students can legitimately have competing claims open on the
     * same item - staff decide between them. But once one is approved the
     * item moves to Claimed, and the losing claim must stop being
     * approvable, or both students get "your claim was approved, bring
     * your student ID to collect it" for one object.
     *
     * Rejecting a losing claim stays allowed, so staff can still clear
     * the queue.
     */
    public function approve(User $user, Claim $claim): Response
    {
        $canReview = $this->review($user, $claim);

        if ($canReview->denied()) {
            return $canReview;
        }

        if (! in_array($claim->foundItem?->status, [FoundItemStatus::PendingVerification, FoundItemStatus::Verified], true)) {
            return Response::deny('This item is no longer available - another claim has already been approved.');
        }

        return Response::allow();
    }

    /** Recording that the item was physically collected. */
    public function complete(User $user, Claim $claim): Response
    {
        if (! $user->canActAsStaff()) {
            return Response::deny('Only staff can record a return.');
        }

        if ($claim->status !== ClaimStatus::Approved) {
            return Response::deny('Only an approved claim can be marked as returned.');
        }

        return Response::allow();
    }

    public function cancel(User $user, Claim $claim): Response
    {
        if (! $this->owns($user, $claim)) {
            return Response::denyAsNotFound();
        }

        return $claim->status->isFinal()
            ? Response::deny('This claim is already closed.')
            : Response::allow();
    }

    private function owns(User $user, Claim $claim): bool
    {
        return $claim->claimant_id === $user->id;
    }
}
