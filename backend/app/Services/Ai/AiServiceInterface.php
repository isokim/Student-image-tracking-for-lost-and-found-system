<?php

namespace App\Services\Ai;

use App\Models\ItemImage;

/**
 * The boundary between Laravel and the AI.
 *
 * Everything model-specific - Ollama, SigLIP, FAISS, vector dimensions,
 * prompt wording - lives behind this interface, in the Python service.
 * Laravel only ever deals in the three plain-PHP result objects below.
 *
 * That is what makes the AI service relocatable: moving it to a cloud
 * host means pointing services.ai.url somewhere else. No Laravel code
 * changes, no migration, and the React app never finds out, because it
 * only ever talks to Laravel.
 *
 * It is also why authorisation is absent from this interface. Python
 * decides what an image looks like; it never decides who may see it.
 */
interface AiServiceInterface
{
    /**
     * Ask the vision model what is in a photo.
     *
     * Advisory only. The result is stored alongside what the student
     * typed, never on top of it - a model that guesses "black wallet"
     * for a navy purse must not rewrite the owner's own words.
     */
    public function analyzeImage(ItemImage $image): ImageAnalysisResult;

    /**
     * Turn a photo into a vector and store it in the AI service's index.
     *
     * Returns the reference FAISS knows it by. Laravel stores only that
     * reference - the vector itself stays on the Python side.
     */
    public function generateEmbedding(ItemImage $image, string $itemType, int $itemId): EmbeddingResult;

    /**
     * Ask for the nearest items to a given embedding reference.
     *
     * Returns candidates with a raw similarity score, in no particular
     * order of business relevance. Turning similarity into a match is
     * Laravel's job (MatchScoringService), because that involves
     * category, location, time and ownership - none of which the AI
     * service knows or should know about.
     *
     * @return SimilarItem[]
     */
    public function findSimilarItems(
        string $embeddingReference,
        string $targetItemType,
        int $limit = 20,
    ): array;

    /**
     * Embed an uploaded image that is not attached to a stored item.
     *
     * Used by the AI search endpoint, where a student uploads a photo of
     * what they lost without filing a report.
     *
     * @param  string  $contents  raw image bytes
     */
    public function embedRawImage(string $contents, string $mimeType): EmbeddingResult;

    /**
     * Whether the service is reachable right now.
     *
     * Callers use this to degrade gracefully rather than failing a user
     * request because a local Python process is not running.
     */
    public function isAvailable(): bool;
}
