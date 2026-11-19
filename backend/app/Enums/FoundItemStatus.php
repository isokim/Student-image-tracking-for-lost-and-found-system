<?php

namespace App\Enums;

/**
 * pending_verification - handed in, a staff member has not seen it yet
 * verified             - staff confirmed it physically exists; claimable
 *                        (shown to users as "Available to claim")
 * claimed              - an approved claim is attached, awaiting collection
 *                        (shown to users as "Awaiting collection")
 * returned             - handed to its owner
 * rejected             - staff rejected the report (duplicate, spam, not a real item)
 */
enum FoundItemStatus: string
{
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Claimed = 'claimed';
    case Returned = 'returned';
    case Rejected = 'rejected';

    /**
     * Found reports should appear on the student board as soon as they
     * are filed. Staff verification can still confirm the hand-in later,
     * but hiding pending reports makes student-to-student testing and
     * matching look broken.
     */
    public function isPubliclyVisible(): bool
    {
        return in_array($this, [self::PendingVerification, self::Verified, self::Claimed, self::Returned], true);
    }

    /**
     * Only verified items can be claimed. A pending item is visible (so
     * matches can point at it) but staff have not yet confirmed it was
     * physically handed in, so nobody can claim it until they have.
     */
    public function isClaimable(): bool
    {
        return $this === self::Verified;
    }
}
