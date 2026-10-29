<?php

namespace App\Services\Matching;

use App\Enums\FoundItemStatus;
use App\Enums\LostItemStatus;
use App\Enums\MatchStatus;
use App\Events\MatchCreated;
use App\Models\FoundItem;
use App\Models\ItemMatch;
use App\Models\LostItem;
use App\Notifications\PossibleMatchFound;
use App\Services\Ai\AiServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds possible matches for an item and records them.
 *
 * The division of labour matters here. The AI service answers one narrow
 * question - "which images look like this one?" - and Laravel does
 * everything else: deciding which items are eligible, combining visual
 * similarity with category, place and time, applying the threshold, and
 * working out who is allowed to be told.
 *
 * That is why this class works fine with NullAiService. Without vectors
 * it falls back to database candidates and text matching: fewer and
 * weaker suggestions, but a working system.
 */
class MatchFinderService
{
    public function __construct(
        private readonly AiServiceInterface $ai,
        private readonly MatchScoringService $scorer,
    ) {
    }

    /**
     * Find found items that might be this lost report.
     *
     * @return ItemMatch[] the matches newly created by this run
     */
    public function findForLostItem(LostItem $lost): array
    {
        if (! $lost->status->isOpen()) {
            return [];
        }

        $lost->loadMissing(['user']);

        // Ask the AI first: its answers widen the candidate set, so they
        // have to be known before candidates are selected.
        $imageScores = $this->imageScoresFor($lost, 'found_item');
        $candidates = $this->candidateFoundItems($lost, $imageScores);

        $created = [];

        foreach ($candidates as $found) {
            $score = $this->scorer->score($lost, $found, $imageScores[$found->id] ?? null);

            if (! $score->meetsThreshold()) {
                continue;
            }

            $match = $this->recordMatch($lost, $found, $score);

            if ($match !== null) {
                $created[] = $match;
            }
        }

        $this->afterMatching($lost, $created);

        return $created;
    }

    /**
     * The mirror case: a newly verified found item is checked against
     * every open lost report. Without this, matching would only ever run
     * in one direction and an item handed in today would never be paired
     * with a report filed last week.
     *
     * @return ItemMatch[]
     */
    public function findForFoundItem(FoundItem $found): array
    {
        if (! $found->status->isPubliclyVisible()) {
            return [];
        }

        $found->loadMissing(['user']);

        $imageScores = $this->imageScoresFor($found, 'lost_item');
        $created = [];

        foreach ($this->candidateLostItems($found, $imageScores) as $lost) {
            $score = $this->scorer->score($lost, $found, $imageScores[$lost->id] ?? null);

            if (! $score->meetsThreshold()) {
                continue;
            }

            $match = $this->recordMatch($lost, $found, $score);

            if ($match !== null) {
                $created[] = $match;

                // Notified per lost report rather than in one batch,
                // because each match here belongs to a different student.
                $this->afterMatching($lost, [$match]);
            }
        }

        return $created;
    }

    // -----------------------------------------------------------------
    // Candidate selection
    // -----------------------------------------------------------------

    /**
     * Which found items are worth scoring at all.
     *
     * Publicly visible found items: pending reports are included so a
     * newly handed-in item can alert the student who reported it lost
     * without waiting for a staff verification step.
     */
    /**
     * @param  array<int, float>  $imageScores  visual candidates from FAISS,
     *        keyed by found-item id. Those ids are force-included, because
     *        the whole point of vector search is to surface an item the
     *        date/text window might rank poorly.
     */
    private function candidateFoundItems(LostItem $lost, array $imageScores = []): Collection
    {
        $window = (int) config('matching.time_window_days', 30);
        $limit = (int) config('matching.candidates.from_vector_search', 50) * 2;

        return FoundItem::query()
            // 'user' is eager-loaded because scoring a whole collection
            // with preventLazyLoading on would otherwise throw on the
            // second row. See the note in candidateLostItems.
            ->with(['category', 'user'])
            ->whereIn('status', [FoundItemStatus::PendingVerification, FoundItemStatus::Verified])
            // The finder's own item is never a match for their own report.
            ->where('user_id', '!=', $lost->user_id)
            ->where(function ($query) use ($lost, $window, $imageScores) {
                $query->where(function ($inner) use ($lost, $window) {
                    // Nothing is found before it is lost (with a day of
                    // slack for people misremembering when they lost it).
                    $inner->where('found_at', '>=', $lost->lost_at->copy()->subDay())
                        ->where('found_at', '<=', $lost->lost_at->copy()->addDays($window));
                });

                /**
                 * Visually similar items are considered even if they fall
                 * outside the date window. Previously the AI's answers
                 * were used only as a score lookup, so an item FAISS
                 * ranked first could be dropped before it was ever scored
                 * - half the weighting applied by luck.
                 */
                if ($imageScores !== []) {
                    $query->orWhereIn('id', array_keys($imageScores));
                }
            })
            /**
             * Ordered before limiting. Without this MySQL returns an
             * arbitrary (in practice oldest-first) slice, so with more
             * candidates than the limit the most recent items - the ones
             * most likely to be the match - were never scored.
             */
            ->orderByDesc('found_at')
            ->limit($limit)
            ->get();
    }

    private function candidateLostItems(FoundItem $found, array $imageScores = []): Collection
    {
        $window = (int) config('matching.time_window_days', 30);
        $limit = (int) config('matching.candidates.from_vector_search', 50) * 2;

        return LostItem::query()
            /**
             * 'user' matters here: afterMatching() notifies
             * $lost->user, and this path scores a collection. Laravel
             * only arms preventLazyLoading on result sets with more than
             * one row, so a missing eager-load here throws on the second
             * candidate - meaning the first student is notified and
             * everyone after them silently is not.
             */
            ->with(['category', 'user'])
            ->whereIn('status', [LostItemStatus::Active, LostItemStatus::Matched])
            ->where('user_id', '!=', $found->user_id)
            ->where(function ($query) use ($found, $window, $imageScores) {
                $query->where(function ($inner) use ($found, $window) {
                    $inner->where('lost_at', '<=', $found->found_at->copy()->addDay())
                        ->where('lost_at', '>=', $found->found_at->copy()->subDays($window));
                });

                if ($imageScores !== []) {
                    $query->orWhereIn('id', array_keys($imageScores));
                }
            })
            ->orderByDesc('lost_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Ask the AI service for visual similarity, keyed by candidate id.
     *
     * Returns an empty array when the item has no embedding or the AI
     * service is unavailable, in which case scoring simply proceeds
     * without an image component.
     *
     * @return array<int, float>
     */
    private function imageScoresFor(LostItem|FoundItem $item, string $targetType): array
    {
        $embedding = $item->embedding;

        if ($embedding === null) {
            return [];
        }

        $similar = $this->ai->findSimilarItems(
            $embedding->embedding_reference,
            $targetType,
            (int) config('matching.candidates.from_vector_search', 50),
        );

        $scores = [];

        foreach ($similar as $candidate) {
            if ($candidate->itemType === $targetType) {
                $scores[$candidate->itemId] = $candidate->score;
            }
        }

        return $scores;
    }

    // -----------------------------------------------------------------
    // Recording
    // -----------------------------------------------------------------

    /**
     * Returns the match only if it is new. Re-running the matcher must
     * not notify a student twice about the same pair, so an existing row
     * is updated with the fresh score and reported as "not new".
     */
    private function recordMatch(LostItem $lost, FoundItem $found, MatchScore $score): ?ItemMatch
    {
        return DB::transaction(function () use ($lost, $found, $score) {
            $existing = ItemMatch::where('lost_item_id', $lost->id)
                ->where('found_item_id', $found->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                /**
                 * Update the score, but leave `status` alone. The student
                 * may have dismissed this suggestion already, and a
                 * rescoring run must not resurrect it.
                 */
                $existing->update($score->toAttributes());

                return null;
            }

            $match = new ItemMatch(array_merge($score->toAttributes(), [
                'lost_item_id' => $lost->id,
                'found_item_id' => $found->id,
            ]));
            $match->save();

            return $match;
        });
    }

    /**
     * @param  ItemMatch[]  $matches
     */
    /**
     * Keeps only the strongest N matches for a lost report.
     *
     * Without this, matching.candidates.max_stored_per_item was config
     * nobody read, and a common item ("black phone") could accumulate an
     * unbounded list of weak suggestions that buries the good ones.
     *
     * Dismissed and claim-started matches are never pruned - those record
     * a decision the student already made.
     */
    private function pruneWeakMatches(LostItem $lost): void
    {
        $keep = (int) config('matching.candidates.max_stored_per_item', 20);

        $surplus = ItemMatch::where('lost_item_id', $lost->id)
            ->where('status', MatchStatus::PendingReview)
            ->orderByDesc('overall_score')
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($surplus->isNotEmpty()) {
            ItemMatch::whereIn('id', $surplus)->delete();
        }
    }

    private function afterMatching(LostItem $lost, array $matches): void
    {
        if ($matches === []) {
            return;
        }

        $this->pruneWeakMatches($lost);

        if ($lost->status === LostItemStatus::Active) {
            $lost->status = LostItemStatus::Matched;
            $lost->save();

            $lost->statusHistory()->create([
                'from_status' => LostItemStatus::Active->value,
                'to_status' => LostItemStatus::Matched->value,
                'changed_by' => null, // the system, not a person
                'note' => 'Possible match found.',
            ]);
        }

        /**
         * Notify about the strongest suggestion only.
         *
         * A rescoring run can produce several matches at once; five
         * separate "we may have found your item" alerts for the same
         * report would be noise, and the student opens the match list
         * anyway.
         */
        $best = collect($matches)->sortByDesc('overall_score')->first();

        $best->loadMissing(['lostItem', 'foundItem']);

        $lost->user->notify(new PossibleMatchFound($best));
        MatchCreated::dispatch($best);
    }
}
