<?php

namespace App\Enums;

/**
 * Note there is no "guaranteed" case, by design. The system suggests;
 * a human decides. The UI renders these as "Possible match" /
 * "Likely match" and never as a certainty.
 */
enum MatchConfidence: string
{
    case Possible = 'possible';
    case Likely = 'likely';
}
