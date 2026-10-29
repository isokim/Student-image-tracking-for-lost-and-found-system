<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notifications belong to exactly one person. Not even an admin reads
 * someone else's - there is no before() hook here, because an admin
 * needing to see what a student was told should look at the audit log,
 * not at their inbox.
 */
class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        // The controller returns only $user->notifications().
        return true;
    }

    public function view(User $user, DatabaseNotification $notification): Response
    {
        return $this->owns($user, $notification)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function markAsRead(User $user, DatabaseNotification $notification): Response
    {
        return $this->owns($user, $notification)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, DatabaseNotification $notification): Response
    {
        return $this->owns($user, $notification)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    private function owns(User $user, DatabaseNotification $notification): bool
    {
        return $notification->notifiable_id === $user->id
            && $notification->notifiable_type === $user->getMorphClass();
    }
}
