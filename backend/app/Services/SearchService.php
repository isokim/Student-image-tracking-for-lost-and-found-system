<?php

namespace App\Services;

use App\Models\Category;
use App\Models\FoundItem;
use App\Models\LostItem;
use App\Models\User;
use App\Services\Ai\AiServiceInterface;
use App\Services\Matching\MatchScoringService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;

/**
 * Keyword/filter search, and AI-assisted image search.
 *
 * Both paths return only what the caller is allowed to see. Search is a
 * classic way to leak records sideways - a filter that quietly includes
 * unverified items would expose everything handed in today - so the
 * visibility scope is applied here, at the query, not left to the
 * resource to hide afterwards.
 */
class SearchService
{
    public function __construct(
        private readonly AiServiceInterface $ai,
        private readonly MatchScoringService $scorer,
    ) {
    }

    /**
     * @param  array  $filters  validated by SearchRequest
     */
    public function searchFoundItems(array $filters, User $viewer): Collection
    {
        return $this->applyFilters(
            FoundItem::query()
                ->withApiRelations()
                // Public found reports. Staff use their own queue
                // endpoint to triage what is awaiting verification.
                ->publiclyVisible(),
            $filters,
            'found_at',
        )->limit(100)->get();
    }

    public function searchLostItems(array $filters, User $viewer): Collection
    {
        return $this->applyFilters(
            LostItem::query()->withApiRelations()->open(),
            $filters,
            'lost_at',
        )->limit(100)->get();
    }

    /**
     * Image search: "here is a photo of what I lost, what have you got?"
     *
     * Laravel orchestrates; it does not do the vision work. The image is
     * embedded by the AI service, FAISS returns visually similar items,
     * and then the same hybrid scorer used for automatic matching ranks
     * them - so a search result and a suggested match are scored on the
     * same basis rather than by two different rules.
     *
     * @return array<int, array{item: FoundItem, score: float, confidence: string}>
     */
    public function searchByImage(UploadedFile $image, array $filters, User $viewer): array
    {
        // Throws AiServiceUnavailableException if the service is off; the
        // controller turns that into a 503 rather than a silent empty list,
        // because "no results" and "search is broken" mean different things
        // to the person who just uploaded a photo of their missing laptop.
        $embedding = $this->ai->embedRawImage(
            file_get_contents($image->getRealPath()),
            $image->getMimeType(),
        );

        $similar = $this->ai->findSimilarItems(
            $embedding->reference,
            'found_item',
            (int) config('matching.candidates.from_vector_search', 50),
        );

        if ($similar === []) {
            return [];
        }

        $scoresByItemId = [];
        foreach ($similar as $candidate) {
            $scoresByItemId[$candidate->itemId] = $candidate->score;
        }

        /**
         * SECURITY: the ids come from the AI service, which knows nothing
         * about verification status or ownership. They are therefore
         * treated as a shortlist to be re-checked, never as a result set -
         * publiclyVisible() is what stops vector search surfacing an item
         * that should not be on the student board.
         */
        $items = $this->applyFilters(
            FoundItem::query()
                ->withApiRelations()
                ->publiclyVisible()
                ->whereIn('id', array_keys($scoresByItemId)),
            $filters,
            'found_at',
        )->get();

        $results = [];

        foreach ($items as $item) {
            $score = $scoresByItemId[$item->id] ?? 0.0;

            $results[] = [
                'item' => $item,
                'score' => $score,
                'confidence' => $score >= (float) config('matching.thresholds.likely_score')
                    ? 'likely'
                    : 'possible',
            ];
        }

        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $results;
    }

    /**
     * Shared filtering for both item types.
     *
     * Everything goes through the query builder with bound parameters -
     * there is no string concatenation anywhere here, so a search term
     * like "'; DROP TABLE users; --" is just a term that matches nothing.
     */
    private function applyFilters(Builder $query, array $filters, string $dateColumn): Builder
    {
        if (! empty($filters['query'])) {
            $term = $filters['query'];

            $query->where(function (Builder $q) use ($term) {
                // LIKE wildcards in user input are escaped by the binding,
                // but % and _ would still act as wildcards, so they are
                // neutralised explicitly.
                $escaped = '%'.addcslashes($term, '%_\\').'%';

                $q->where('title', 'like', $escaped)
                    ->orWhere('description', 'like', $escaped)
                    // Typed locations are free text, so the place someone
                    // writes is also just another searchable word.
                    ->orWhere('location_text', 'like', $escaped);
            });
        }

        if (! empty($filters['category'])) {
            $categoryId = Category::where('slug', $filters['category'])->value('id');
            // A slug that does not exist must return nothing, not
            // everything - hence the explicit -1 rather than skipping.
            $query->where('category_id', $categoryId ?? -1);
        }

        // Free-text location filter, matched the same way as colour.
        if (! empty($filters['location'])) {
            $query->where('location_text', 'like', '%'.addcslashes($filters['location'], '%_\\').'%');
        }

        if (! empty($filters['colour'])) {
            $query->where('colour', 'like', '%'.addcslashes($filters['colour'], '%_\\').'%');
        }

        if (! empty($filters['dateFrom'])) {
            $query->where($dateColumn, '>=', $filters['dateFrom']);
        }

        if (! empty($filters['dateTo'])) {
            $query->where($dateColumn, '<=', $filters['dateTo']);
        }

        return $query->latest($dateColumn);
    }
}
