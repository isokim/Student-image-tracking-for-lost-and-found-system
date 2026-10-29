<?php

namespace App\Policies;

use App\Models\User;

/**
 * Categories.
 *
 * These are global reference data - there is no per-record owner for a
 * policy to compare against - so every ability is simply "are you an
 * admin?".
 *
 * It exists anyway because the project's stated rule is middleware AND
 * policy on every sensitive route. Those controllers previously relied
 * on `role:admin` alone, which is correct today but becomes a privilege
 * escalation the moment a route is moved out of the admin group or the
 * middleware string is mistyped. Two independent checks mean one
 * mistake is not enough.
 */
class ReferenceDataPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user): bool
    {
        return $user->isAdmin();
    }
}
