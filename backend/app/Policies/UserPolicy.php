<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * User administration.
 *
 * Note this policy has NO before() hook granting admins everything.
 * The dangerous actions here are precisely the ones an admin performs,
 * so each is spelled out with its own guard rails - an admin must not
 * be able to lock themselves out or quietly demote a colleague without
 * the rules being visible.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $target): bool
    {
        // You can always read your own record; otherwise admin only.
        return $user->id === $target->id || $user->isAdmin();
    }

    /** Creating staff and admin accounts - the controlled process. */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    public function suspend(User $user, User $target): Response
    {
        if (! $user->isAdmin()) {
            return Response::deny('Only administrators can suspend accounts.');
        }

        // Stops an admin locking themselves out of the system.
        if ($user->id === $target->id) {
            return Response::deny('You cannot suspend your own account.');
        }

        if ($target->isAdmin()) {
            return Response::deny('Administrator accounts cannot be suspended from here.');
        }

        return Response::allow();
    }

    public function reinstate(User $user, User $target): Response
    {
        return $user->isAdmin()
            ? Response::allow()
            : Response::deny('Only administrators can reinstate accounts.');
    }

    public function changeRole(User $user, User $target): Response
    {
        if (! $user->isAdmin()) {
            return Response::deny('Only administrators can change roles.');
        }

        // Prevents an admin demoting themselves and leaving the system
        // with no one able to administer it.
        if ($user->id === $target->id) {
            return Response::deny('You cannot change your own role.');
        }

        return Response::allow();
    }

    public function delete(User $user, User $target): Response
    {
        if (! $user->isAdmin()) {
            return Response::deny('Only administrators can delete accounts.');
        }

        if ($user->id === $target->id) {
            return Response::deny('You cannot delete your own account.');
        }

        return Response::allow();
    }
}
