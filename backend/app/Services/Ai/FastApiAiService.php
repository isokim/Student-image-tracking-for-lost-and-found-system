<?php

namespace App\Services\Ai;

use App\Models\ItemImage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Talks to the local Python FastAPI service over HTTP.
 *
 * This class is the ONLY place in Laravel that knows the AI service's
 * URL, its endpoint names or its JSON shape. Everything else depends on
 * AiServiceInterface. Replacing this with a cloud implementation is a
 * one-line binding change in AiServiceProvider.
 *
 * Failure policy: every method degrades rather than throws. The AI is an
 * enhancement, not a dependency - a student reporting a lost bag must
 * not see an error because Ollama is not running. Callers get an empty
 * result and the system carries on with text matching.
 */
class FastApiAiService implements AiServiceInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly ?string $token = null,
        /**
         * Image analysis gets its own, much longer budget.
         *
         * Embedding and similarity are sub-second; description generation
         * runs a 7B vision model on CPU and takes minutes - measured at
         * 135s for one image here. Sharing one timeout means either
         * analysis always fails, or every fast call waits far too long
         * before giving up on a dead service. Nothing waits on analysis
         * interactively: it happens in a queued job.
         */
        private readonly int $analyzeTimeout = 300,
    ) {
    }

    public function analyzeImage(ItemImage $image): ImageAnalysisResult
    {
        $contents = $this->readImage($image);

        if ($contents === null) {
            return new ImageAnalysisResult;
        }

        $response = $this->safely(fn () => $this->request($this->analyzeTimeout)
            ->attach('image', $contents, $image->original_name ?: 'upload.jpg')
            ->post('/analyze'));

        if ($response === null || $response->failed()) {
            return new ImageAnalysisResult;
        }

        $data = $response->json();

        return new ImageAnalysisResult(
            category: $data['category'] ?? null,
            colour: $data['colour'] ?? $data['color'] ?? null,
            brand: $data['brand'] ?? null,
            features: is_array($data['features'] ?? null) ? $data['features'] : [],
            description: $data['description'] ?? null,
            modelName: $data['model_name'] ?? 'unknown',
            modelVersion: $data['model_version'] ?? 'unknown',
        );
    }

    public function generateEmbedding(ItemImage $image, string $itemType, int $itemId): EmbeddingResult
    {
        $contents = $this->readImage($image);

        if ($contents === null) {
            throw new AiServiceUnavailableException('The image file could not be read.');
        }

        $response = $this->safely(fn () => $this->request()
            ->attach('image', $contents, $image->original_name ?: 'upload.jpg')
            // The AI service indexes the vector under this identity so a
            // later similarity search can say which item it belongs to.
            ->post('/embed', [
                'item_type' => $itemType,
                'item_id' => (string) $itemId,
            ]));

        if ($response === null || $response->failed()) {
            throw new AiServiceUnavailableException('The AI service did not return an embedding.');
        }

        $data = $response->json();

        if (empty($data['reference'])) {
            throw new AiServiceUnavailableException('The AI service returned no embedding reference.');
        }

        return new EmbeddingResult(
            reference: (string) $data['reference'],
            modelName: $data['model_name'] ?? 'unknown',
            modelVersion: $data['model_version'] ?? 'unknown',
            dimensions: isset($data['dimensions']) ? (int) $data['dimensions'] : null,
        );
    }

    public function findSimilarItems(string $embeddingReference, string $targetItemType, int $limit = 20): array
    {
        $response = $this->safely(fn () => $this->request()->post('/similar', [
            'reference' => $embeddingReference,
            'target_item_type' => $targetItemType,
            'limit' => $limit,
        ]));

        if ($response === null || $response->failed()) {
            return [];
        }

        return $this->mapSimilarItems($response->json('results') ?? []);
    }

    public function embedRawImage(string $contents, string $mimeType): EmbeddingResult
    {
        $response = $this->safely(fn () => $this->request()
            ->attach('image', $contents, 'search'.$this->extensionFor($mimeType))
            // transient=true tells the AI service to embed without adding
            // the vector to the persistent index - a search query is not
            // an item and must not pollute future similarity results.
            ->post('/embed', ['transient' => 'true']));

        if ($response === null || $response->failed() || empty($response->json('reference'))) {
            throw new AiServiceUnavailableException('The AI service could not process that image.');
        }

        $data = $response->json();

        return new EmbeddingResult(
            reference: (string) $data['reference'],
            modelName: $data['model_name'] ?? 'unknown',
            modelVersion: $data['model_version'] ?? 'unknown',
            dimensions: isset($data['dimensions']) ? (int) $data['dimensions'] : null,
        );
    }

    public function isAvailable(): bool
    {
        /**
         * Short, but not brutally so.
         *
         * This is asked during a user request, so it must not hang the
         * page on a process that is not running - a refused connection
         * fails in milliseconds, which is the common case. The 5 seconds
         * is headroom for a service that is up but still warming a model
         * on its first request; at 2 seconds a merely-slow service was
         * reported as down.
         */
        try {
            return Http::baseUrl($this->baseUrl)->timeout(5)->get('/health')->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function request(?int $timeout = null): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->timeout($timeout ?? $this->timeout)
            ->acceptJson();

        if ($this->token) {
            $request = $request->withToken($this->token);
        }

        return $request;
    }

    /**
     * Wraps a call so a dead Python process becomes a null result rather
     * than a 500 for the student who triggered it.
     */
    private function safely(callable $call): mixed
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            Log::warning('AI service unreachable.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function readImage(ItemImage $image): ?string
    {
        $disk = Storage::disk($image->disk);

        if (! $disk->exists($image->path)) {
            Log::warning('Image file missing for AI analysis.', ['item_image_id' => $image->id]);

            return null;
        }

        return $disk->get($image->path);
    }

    /** @return SimilarItem[] */
    private function mapSimilarItems(array $results): array
    {
        $items = [];

        foreach ($results as $row) {
            if (! isset($row['item_type'], $row['item_id'], $row['score'])) {
                continue;
            }

            $items[] = new SimilarItem(
                itemType: (string) $row['item_type'],
                itemId: (int) $row['item_id'],
                // Clamped: a similarity outside 0..1 would silently skew
                // every weighted score that uses it.
                score: max(0.0, min(1.0, (float) $row['score'])),
            );
        }

        return $items;
    }

    private function extensionFor(string $mimeType): string
    {
        return match ($mimeType) {
            'image/png' => '.png',
            'image/webp' => '.webp',
            default => '.jpg',
        };
    }
}
