<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channel authorisation
|--------------------------------------------------------------------------
|
| This file is the authorisation layer for WebSockets, and it matters as
| much as the policies do. Reverb asks Laravel "may this user subscribe
| to this channel?" before delivering anything, and each callback below
| is that answer.
|
| Get one of these wrong and a student receives another student's
| notifications in real time - without ever touching an API endpoint,
| so no policy would have run.
|
| Every channel here is private. There are no public channels in this
| application, because everything broadcast concerns somebody's property
| or somebody's claim.
|
*/

/**
 * A single user's own channel. Carries their notifications, their match
 * suggestions, and updates to their own claims.
 *
 * The check is deliberately identity only - not "is an admin", not "is
 * staff". Nobody listens in on another person's channel, which mirrors
 * NotificationPolicy.
 */
Broadcast::channel('users.{userId}', function (User $user, int $userId) {
    /**
     * The suspension check matters here as much as on the two channels
     * below. /broadcasting/auth is registered under the `web` group, so
     * it does NOT pass through the `active` middleware - without this a
     * suspended account whose HTTP access was cut off, and whose sessions
     * were deleted, could still hold an open socket and keep receiving
     * its own notification stream.
     */
    return $user->id === $userId && ! $user->isSuspended();
});

/**
 * The shared staff channel: new claims to review, items verified.
 *
 * Suspended accounts are excluded explicitly. The `active` middleware
 * guards HTTP requests, but a WebSocket subscription does not pass
 * through it, so without this line a suspended staff member would keep
 * receiving the review queue live.
 */
Broadcast::channel('staff', function (User $user) {
    return $user->canActAsStaff() && ! $user->isSuspended();
});

/**
 * Admin-only channel, for system-wide activity.
 */
Broadcast::channel('admin', function (User $user) {
    return $user->isAdmin() && ! $user->isSuspended();
});
