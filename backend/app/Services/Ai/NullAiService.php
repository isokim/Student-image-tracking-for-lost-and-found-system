<?php

namespace App\Services\Ai;

use App\Models\ItemImage;

/**
 * Stand-in used when the Python service is switched off
 * (AI_SERVICE_ENABLED=false) or not installed.
 *
 * This exists so the whole application is runnable and testable without
 * Ollama, a vector index, or a Python environment. Everything that does
 * not need the AI - reporting, verification, claims, notifications,
 * keyword search, and text-based matching - behaves identically. Only
 * image similarity is missing.
 *
 * It also keeps tests honest: a test that passes here is not quietly
 * depending on a model being installed on the machine running it.
 */
class NullAiService implements AiServiceInterface
{
    public function analyzeImage(ItemImage $image): ImageAnalysisResult
    {
        // Empty, not fabricated. Inventing a category here would put
        // guesses into item_ai_analysis that no model ever made.
        return new ImageAnalysisResult;
    }

    public function generateEmbedding(ItemImage $image, string $itemType, int $itemId): EmbeddingResult
    {
        throw new AiServiceUnavailableException(
            'The AI service is disabled, so no embedding can be generated.'
        );
    }

    public function findSimilarItems(string $embeddingReference, string $targetItemType, int $limit = 20): array
    {
        // No visual candidates. Matching still runs on the text and
        // metadata signals, which is why this returns [] rather than
        // throwing.
        return [];
    }

    public function embedRawImage(string $contents, string $mimeType): EmbeddingResult
    {
        throw new AiServiceUnavailableException(
            'Image search is unavailable because the AI service is disabled.'
        );
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
