<?php

namespace App\Services\Ai;

/**
 * What the vision model thinks it saw. A plain value object, so nothing
 * in Laravel depends on the AI service's JSON shape beyond the one
 * place that builds this (FastApiAiService).
 */
class ImageAnalysisResult
{
    public function __construct(
        public readonly ?string $category = null,
        public readonly ?string $colour = null,
        public readonly ?string $brand = null,
        /** @var string[] e.g. ["cracked screen", "blue case"] */
        public readonly array $features = [],
        public readonly ?string $description = null,
        public readonly string $modelName = 'unknown',
        public readonly string $modelVersion = 'unknown',
    ) {
    }

    /** Nothing useful came back; there is no point storing a row. */
    public function isEmpty(): bool
    {
        return $this->category === null
            && $this->colour === null
            && $this->brand === null
            && $this->features === []
            && $this->description === null;
    }
}
