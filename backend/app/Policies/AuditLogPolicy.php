<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * The audit trail is admin-only and read-only.
 *
 * There is no create(), update() or delete() here on purpose. Audit
 * entries are written by AuditLogger as a side effect of real actions,
 * never through an endpoint - and nothing may edit or remove them, or
 * the trail would be worthless as evidence.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->isAdmin();
    }
}
