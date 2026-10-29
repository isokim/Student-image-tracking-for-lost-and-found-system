<?php

namespace App\Enums;

/**
 * draft          - started but not submitted
 * pending_review - submitted, waiting on staff
 * approved       - staff accepted it; the student may collect the item
 * rejected       - staff refused it
 * completed      - the item was physically handed over
 */
enum ClaimStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Completed = 'completed';

    /** Staff can only act on a claim that is waiting for them. */
    public function isReviewable(): bool
    {
        return $this === self::PendingReview;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Rejected, self::Completed], true);
    }
}
