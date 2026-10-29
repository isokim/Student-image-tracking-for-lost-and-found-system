<?php

namespace App\Enums;

enum MatchStatus: string
{
    case PendingReview = 'pending_review';
    case Dismissed = 'dismissed';
    case ClaimStarted = 'claim_started';
}
