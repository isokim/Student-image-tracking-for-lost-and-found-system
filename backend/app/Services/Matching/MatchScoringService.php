<?php

namespace App\Services\Matching;

use App\Models\FoundItem;
use App\Models\LostItem;
use InvalidArgumentException;

/**
 * Turns a (lost item, found item) pair into a hybrid score.
 *
 * Pure computation: no database writes, no HTTP, no notifications. That
 * makes it straightforward to unit-test the scoring rules on their own,
 * and keeps the question "how good is this match?" separate from "what
 * should the system do about it?".
 *
 * The weights live in config/matching.php, not in this file.
 */
class MatchScoringService
{
    private array $weights;

    public function __construct()
    {
        $this->weights = config('matching.weights');

        /**
         * Guard rather than trust. If someone edits the config so the
         * weights no longer sum to 1, every score silently lands on a
         * different scale and the thresholds stop meaning anything -
         * a bug that would be very hard to spot from the output.
         */
        $sum = array_sum($this->weights);

        if (abs($sum - 1.0) > 0.0001) {
            throw new InvalidArgumentException(
                "config('matching.weights') must sum to 1.0, got {$sum}."
            );
        }
    }

    /**
     * @param  float|null  $imageScore  visual similarity from the AI
     *                                  service, or null when either item
     *                                  has no photo to compare.
     */
    public function score(LostItem $lost, FoundItem $found, ?float $imageScore = null): MatchScore
    {
        $components = [
            'text' => $this->textScore($lost, $found),
            'category' => $this->categoryScore($lost, $found),
            'colour' => $this->colourScore($lost, $found),
            'location' => $this->locationScore($lost, $found),
            'time' => $this->timeScore($lost, $found),
        ];

        if ($imageScore !== null) {
            $components['image'] = max(0.0, min(1.0, $imageScore));
        }

        return new MatchScore(
            image: $components['image'] ?? null,
            text: $components['text'],
            category: $components['category'],
            colour: $components['colour'],
            location: $components['location'],
            time: $components['time'],
            overall: $this->combine($components),
        );
    }

    /**
     * Weighted sum of whatever components are present.
     *
     * When there is no image, its weight is redistributed across the
     * remaining components instead of simply vanishing. Otherwise a
     * perfect text-and-metadata match on two photoless reports could
     * never score above 0.50 and would always look worse than a mediocre
     * match that happened to have pictures.
     */
    private function combine(array $components): float
    {
        $weights = $this->weights;

        if (! array_key_exists('image', $components)) {
            unset($weights['image']);

            if (config('matching.redistribute_image_weight_when_missing')) {
                $remaining = array_sum($weights);
                // Scale the rest back up so they sum to 1.0 again.
                $weights = array_map(fn ($w) => $w / $remaining, $weights);
            }
        }

        $total = 0.0;

        foreach ($weights as $key => $weight) {
            $total += ($components[$key] ?? 0.0) * $weight;
        }

        return round(max(0.0, min(1.0, $total)), 4);
    }

    // -----------------------------------------------------------------
    // Individual signals. Each returns 0..1.
    // -----------------------------------------------------------------

    /**
     * Word overlap between the two descriptions.
     *
     * Deliberately simple: this runs in MySQL-land over short student
     * descriptions, and a heavier algorithm would not obviously do
     * better on "black hp laptop" versus "HP laptop, black case". Common
     * words are dropped so "the" and "a" do not inflate the score.
     */
    private function textScore(LostItem $lost, FoundItem $found): float
    {
        $lostWords = $this->significantWords($lost->title.' '.$lost->description);
        $foundWords = $this->significantWords($found->title.' '.$found->description);

        if ($lostWords === [] || $foundWords === []) {
            return 0.0;
        }

        $shared = count(array_intersect($lostWords, $foundWords));

        // Jaccard similarity: shared words over total distinct words.
        $union = count(array_unique(array_merge($lostWords, $foundWords)));

        return $union > 0 ? round($shared / $union, 4) : 0.0;
    }

    /** @return string[] */
    private function significantWords(string $text): array
    {
        $stopWords = [
            'the', 'a', 'an', 'and', 'or', 'of', 'in', 'on', 'at', 'to', 'with',
            'my', 'it', 'is', 'was', 'for', 'this', 'that', 'i', 'have', 'has',
            'lost', 'found', 'item', 'near', 'around', 'some', 'very',
        ];

        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn ($w) => mb_strlen($w) > 2 && ! in_array($w, $stopWords, true),
        )));
    }

    /** Binary: the categories match or they do not. */
    private function categoryScore(LostItem $lost, FoundItem $found): float
    {
        return $lost->category_id === $found->category_id ? 1.0 : 0.0;
    }

    /**
     * Colour comparison is fuzzy on purpose. One student writes "black",
     * another "dark grey"; scoring that as a flat zero would be worse
     * than useless.
     */
    private function colourScore(LostItem $lost, FoundItem $found): float
    {
        return $this->fuzzyTextMatch($lost->colour, $found->colour);
    }

    /**
     * Returns 0.5, not 0, when either side is blank.
     *
     * A missing optional field is not evidence against a match - most
     * students leave some of them empty. Scoring it zero would penalise
     * incomplete reports as if they had actively contradicted each other.
     */
    private function fuzzyTextMatch(?string $a, ?string $b): float
    {
        $a = trim(mb_strtolower($a ?? ''));
        $b = trim(mb_strtolower($b ?? ''));

        if ($a === '' || $b === '') {
            return 0.5;
        }

        if ($a === $b) {
            return 1.0;
        }

        // "navy blue" vs "blue"
        if (str_contains($a, $b) || str_contains($b, $a)) {
            return 0.75;
        }

        similar_text($a, $b, $percent);

        return round($percent / 100, 4);
    }

    /**
     * How similar the two typed locations are.
     *
     * This used to compare location_id and, failing that, the locations
     * table's campus_area - the same building scored 0.6. With the
     * admin-managed list removed, the typed text is the only thing left,
     * so it is compared the same way colour is: fuzzily.
     *
     * The 0.2 floor is deliberate and is not the same as a colour
     * mismatch scoring 0. Different places are weak evidence against a
     * match - people carry things across campus before dropping them - so
     * two completely different places still score above nothing.
     */
    private function locationScore(LostItem $lost, FoundItem $found): float
    {
        // A blank location on either side is neutral, not evidence
        // against - the same rule fuzzyTextMatch applies to colour.
        $lostPlace = trim((string) $lost->location_text);
        $foundPlace = trim((string) $found->location_text);

        if ($lostPlace === '' || $foundPlace === '') {
            return 0.5;
        }

        return max(0.2, $this->fuzzyTextMatch($lostPlace, $foundPlace));
    }

    /**
     * Linear decay from the day it was lost out to the configured window.
     *
     * Found strictly before lost scores 0 - you cannot hand in an item
     * before its owner lost it, so that pairing is almost certainly a
     * different object.
     */
    private function timeScore(LostItem $lost, FoundItem $found): float
    {
        if ($lost->lost_at === null || $found->found_at === null) {
            return 0.5;
        }

        // A little slack: students misremember the exact hour, and an
        // item found the same morning it was lost is a strong signal.
        $hoursEarly = $found->found_at->diffInHours($lost->lost_at, false);

        if ($hoursEarly > 24) {
            return 0.0;
        }

        $daysAfter = max(0, $lost->lost_at->diffInDays($found->found_at, false));
        $window = (int) config('matching.time_window_days', 30);

        if ($daysAfter >= $window) {
            return 0.0;
        }

        return round(1.0 - ($daysAfter / $window), 4);
    }
}
