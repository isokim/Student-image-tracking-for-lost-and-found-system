<?php

namespace App\Services\Ai;

/**
 * A pointer to a vector held by the AI service.
 *
 * Note there is no array of floats here. Laravel never sees the vector,
 * which is exactly why the AI service can be swapped or relocated.
 */
class EmbeddingResult
{
    public function __construct(
        public readonly string $reference,
        public readonly string $modelName,
        public readonly string $modelVersion,
        public readonly ?int $dimensions = null,
    ) {
    }
}
