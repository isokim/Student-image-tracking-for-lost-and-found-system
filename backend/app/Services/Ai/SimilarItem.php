<?php

namespace App\Services\Ai;

/**
 * One candidate returned by vector search.
 *
 * `score` is raw visual similarity, 0..1. It is NOT a match score - it
 * knows nothing about category, location, time or who owns what. Laravel
 * combines it with those signals in MatchScoringService.
 */
class SimilarItem
{
    public function __construct(
        public readonly string $itemType,   // "lost_item" | "found_item"
        public readonly int $itemId,
        public readonly float $score,
    ) {
    }
}
