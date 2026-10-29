<?php

namespace App\Jobs;

use App\Models\ItemEmbedding;
use App\Models\ItemImage;
use App\Services\Ai\AiServiceUnavailableException;
use App\Services\Ai\AiServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Batchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Turns a photo into a vector held by the AI service, and stores the
 * reference to it.
 *
 * Laravel never sees the vector itself - only the id FAISS knows it by.
 * That is what keeps the database independent of the AI implementation
 * and lets the Python service move to a cloud host later.
 */
class GenerateItemEmbedding implements ShouldQueue
{
    /**
     * Batchable is required, not optional: ProcessItemImage runs
     * these two inside a Bus::batch(), and Laravel refuses to batch
     * a job without this trait. It only fails at dispatch time, so
     * a test using Bus::fake() never sees it.
     */
    use Batchable, Queueable;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [10, 60];

    public function __construct(public readonly int $itemImageId)
    {
    }

    public function handle(AiServiceInterface $ai): void
    {
        $image = ItemImage::with('item')->find($this->itemImageId);

        if ($image === null || $image->item === null) {
            return;
        }

        $item = $image->item;

        try {
            $result = $ai->generateEmbedding($image, $item->getMorphClass(), $item->getKey());
        } catch (AiServiceUnavailableException $e) {
            /**
             * Fail the job rather than swallowing the error, so Laravel
             * retries it. An embedding that never gets generated means
             * this item is invisible to image search forever - unlike a
             * failed analysis, that is worth retrying.
             */
            Log::warning('Embedding generation failed.', [
                'item_image_id' => $image->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        /**
         * Written through the model, not through $item->embedding().
         *
         * embedding() is a morphOne()->latestOfMany(), and that
         * one-of-many subquery is applied to updateOrCreate's LOOKUP as
         * well as its read. So with embeddings from two models on one
         * item, re-running the older one failed to find its own row (it
         * is not MAX(id)), fell through to an INSERT, and violated
         * item_embeddings_item_model_unique - the job then burned all
         * three retries and died.
         */
        ItemEmbedding::updateOrCreate(
            [
                'item_type' => $item->getMorphClass(),
                'item_id' => $item->getKey(),
                'model_name' => $result->modelName,
            ],
            [
                'embedding_reference' => $result->reference,
                'model_version' => $result->modelVersion,
                'dimensions' => $result->dimensions,
            ],
        );
    }
}
