<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes the append-only audit trail.
 *
 * Every security-relevant action goes through here rather than calling
 * AuditLog::create() directly, so the IP address, user agent and actor
 * are captured consistently and cannot be forgotten at a call site.
 *
 * Action names use a dot convention - "claim.approved", "auth.login" -
 * so the admin screen can group and filter them.
 */
class AuditLogger
{
    // --- authentication -------------------------------------------------
    public const LOGIN = 'auth.login';
    public const LOGIN_FAILED = 'auth.login_failed';
    public const LOGOUT = 'auth.logout';
    public const REGISTERED = 'auth.registered';
    public const PASSWORD_RESET = 'auth.password_reset';
    public const EMAIL_VERIFIED = 'auth.email_verified';

    // --- items ----------------------------------------------------------
    public const ITEM_CREATED = 'item.created';
    public const ITEM_UPDATED = 'item.updated';
    public const ITEM_DELETED = 'item.deleted';
    public const ITEM_CLOSED = 'item.closed';
    public const ITEM_VERIFIED = 'item.verified';
    public const ITEM_REJECTED = 'item.rejected';
    public const ITEM_RETURNED = 'item.returned';

    // --- claims ---------------------------------------------------------
    public const CLAIM_SUBMITTED = 'claim.submitted';
    public const CLAIM_APPROVED = 'claim.approved';
    public const CLAIM_REJECTED = 'claim.rejected';
    public const CLAIM_COMPLETED = 'claim.completed';

    // --- administration -------------------------------------------------
    public const USER_SUSPENDED = 'admin.user_suspended';
    public const USER_REINSTATED = 'admin.user_reinstated';
    public const USER_ROLE_CHANGED = 'admin.user_role_changed';
    public const STAFF_CREATED = 'admin.staff_created';
    public const CATEGORY_CHANGED = 'admin.category_changed';

    /**
     * Record an action.
     *
     * @param  Model|null  $entity  the record acted upon, if any
     * @param  User|null  $actor  defaults to the authenticated user; pass
     *                            explicitly for events with no session yet
     *                            (a failed login) or for queued jobs.
     */
    public function log(
        string $action,
        ?Model $entity = null,
        ?string $description = null,
        ?User $actor = null,
    ): AuditLog {
        $actor ??= Auth::user();

        return AuditLog::create([
            'user_id' => $actor?->id,
            'action' => $action,
            // getMorphClass() returns the short morph-map alias
            // ("found_item"), which is what the admin UI displays.
            'entity_type' => $entity?->getMorphClass(),
            'entity_id' => $entity?->getKey(),
            'description' => $description,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 1000),
        ]);
    }
}
