<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiSearchRequest;
use App\Http\Requests\SearchRequest;
use App\Http\Resources\FoundItemResource;
use App\Http\Resources\LostItemResource;
use App\Services\Ai\AiServiceUnavailableException;
use App\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class SearchController extends Controller
{
    public function __construct(private readonly SearchService $search)
    {
    }

    /**
     * GET /api/search - keyword and filter search.
     *
     * Defaults to the found board, because someone searching has usually
     * lost something and wants to know if it has been handed in.
     */
    public function index(SearchRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $user = $request->user();

        if (($filters['status'] ?? 'found') === 'lost') {
            return response()->json(
                LostItemResource::collection($this->search->searchLostItems($filters, $user))
            );
        }

        return response()->json(
            FoundItemResource::collection($this->search->searchFoundItems($filters, $user))
        );
    }

    /**
     * POST /api/search/image - AI-assisted search.
     *
     * The flow the brief describes: validate, authorise, hand the image
     * to the AI service, get candidates back, re-fetch those records
     * from MySQL under the visibility rules, score, and return ranked
     * results. Laravel decides what the user may see; Python only ever
     * says what looks similar.
     *
     * The uploaded image is embedded and discarded. It is not stored as
     * an item and is not added to the vector index - a search query is
     * not a report.
     */
    public function image(AiSearchRequest $request): JsonResponse
    {
        try {
            $results = $this->search->searchByImage(
                $request->file('image'),
                $request->validated(),
                $request->user(),
            );
        } catch (AiServiceUnavailableException $e) {
            /**
             * 503, not an empty list.
             *
             * "We found nothing" and "image search is not working" mean
             * very different things to someone who has just uploaded a
             * photo of their missing laptop, and quietly returning [] in
             * the second case would be a lie.
             */
            return response()->json([
                'message' => 'Image search is unavailable at the moment. Try a keyword search instead.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        /**
         * Shaped as ImageSearchResult[] for the React client.
         *
         * `scores` carries ONLY visualSimilarity, because visual
         * similarity is the only thing actually measured here - there is
         * no lost report to compare a description, category or location
         * against, since the student searched with a photo rather than
         * filing one.
         *
         * This previously sent descriptionSimilarity: 0, categoryMatch:
         * false and locationProximity: 'different' as filler, which the
         * UI rendered literally: every result claimed a 0% description
         * match and a failed category match regardless of the items. The
         * keys are omitted now so the component can distinguish "not
         * measured" from "measured and bad".
         */
        return response()->json(array_map(fn (array $row) => [
            'id' => (string) $row['item']->id,
            'matchedItem' => new FoundItemResource($row['item']),
            'foundItem' => new FoundItemResource($row['item']),
            'overallConfidence' => $row['confidence'],
            'scores' => [
                'visualSimilarity' => (int) round($row['score'] * 100),
            ],
            'status' => 'pending_review',
            'createdAt' => now()->toIso8601String(),
        ], $results));
    }
}
