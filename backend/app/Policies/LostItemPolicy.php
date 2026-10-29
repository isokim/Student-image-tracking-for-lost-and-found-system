<?php

namespace App\Policies;

use App\Models\LostItem;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what with a lost report.
 *
 * A lost report is deliberately *browsable*: the whole point of a lost
 * and found board is that someone who picked up a wallet can see who
 * lost it. So view() is open to any signed-in user.
 *
 * What is NOT browsable is the private detail on the report - the
 * owner's extra identifying notes. That is enforced at field level by
 * viewPrivateDetails() below, which LostItemResource consults. Read
 * the two together: "you can see the report, you cannot see the secret
 * that proves it is mine".
 */
class LostItemPolicy
{
    /**
     * Admins bypass every check below.
     *
     * Staff are deliberately NOT included here - they get exactly the
     * permissions granted method by method, so their access stays
     * reviewable rather than implicit.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LostItem $lostItem): bool
    {
        return true;
    }

    /**
     * Field-level authorisation, consulted by LostItemResource.
     *
     * This is the rule the brief calls out: changing the id in
     * GET /api/lost-items/25 shows you the public report, never another
     * student's private notes.
     */
    public function viewPrivateDetails(User $user, LostItem $lostItem): bool
    {
        return $this->owns($user, $lostItem) || $user->canActAsStaff();
    }

    public function create(User $user): bool
    {
        // Any signed-in, active user may report something lost.
        return true;
    }

    public function update(User $user, LostItem $lostItem): Response
    {
        if (! $this->owns($user, $lostItem)) {
            return Response::deny('You can only edit your own reports.');
        }

        if (! $lostItem->status->isOpen()) {
            return Response::deny('This report is closed and can no longer be edited.');
        }

        return Response::allow();
    }

    public function close(User $user, LostItem $lostItem): Response
    {
        if (! $this->owns($user, $lostItem)) {
            return Response::deny('You can only close your own reports.');
        }

        if (! $lostItem->status->isOpen()) {
            return Response::deny('This report is already closed.');
        }

        return Response::allow();
    }

    public function delete(User $user, LostItem $lostItem): Response
    {
        return $this->owns($user, $lostItem)
            ? Response::allow()
            : Response::deny('You can only delete your own reports.');
    }

    private function owns(User $user, LostItem $lostItem): bool
    {
        return $lostItem->user_id === $user->id;
    }
}
