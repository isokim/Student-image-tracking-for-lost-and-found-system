<?php

namespace App\Services\Matching;

use App\Enums\MatchConfidence;

/**
 * The result of scoring one lost/found pair.
 *
 * Every component is carried, not just the total, because the UI shows
 * the student *why* something was suggested - and because keeping the
 * parts means the weights can be changed later without recomputing
 * image similarity, which is the expensive bit.
 */
class MatchScore
{
    public function __construct(
        public readonly ?float $image,
        public readonly float $text,
        public readonly float $category,
        public readonly float $colour,
        public readonly float $location,
        public readonly float $time,
        public readonly float $overall,
    ) {
    }

    /** Below the configured floor, the pair is not worth showing anyone. */
    public function meetsThreshold(): bool
    {
        return $this->overall >= (float) config('matching.thresholds.minimum_score');
    }

    /**
     * Note the ceiling: the best this ever returns is "likely".
     *
     * There is no "certain" or "guaranteed" case, by design. The system
     * suggests; a staff member approving a claim is the only thing that
     * establishes ownership.
     */
    public function confidence(): MatchConfidence
    {
        return $this->overall >= (float) config('matching.thresholds.likely_score')
            ? MatchConfidence::Likely
            : MatchConfidence::Possible;
    }

    /** Shaped for a mass insert/update into item_matches. */
    public function toAttributes(): array
    {
        return [
            'image_score' => $this->image,
            'text_score' => $this->text,
            'category_score' => $this->category,
            'colour_score' => $this->colour,
            'location_score' => $this->location,
            'time_score' => $this->time,
            'overall_score' => $this->overall,
            'confidence' => $this->confidence(),
            'scoring_version' => config('matching.version', 'v1'),
        ];
    }
}
