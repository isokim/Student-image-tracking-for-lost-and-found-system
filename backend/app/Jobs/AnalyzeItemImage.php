<?php

namespace App\Jobs;

use App\Models\ItemAiAnalysis;
use App\Models\ItemImage;
use App\Services\Ai\AiServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Batchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Asks the vision model what is in a photo and stores the answer.
 *
 * Queued because local inference takes seconds to tens of seconds. A
 * student filing a report should get their confirmation immediately;
 * the AI catches up afterwards.
 *
 * The result is advisory. It is written to item_ai_analysis alongside
 * what the student typed and never overwrites their own words - a model
 * that reads a navy rucksack as a "black bag" must not rewrite the
 * owner's description of their own property.
 */
class AnalyzeItemImage implements ShouldQueue
{
    /**
     * Batchable is required, not optional: ProcessItemImage runs
     * these two inside a Bus::batch(), and Laravel refuses to batch
     * a job without this trait. It only fails at dispatch time, so
     * a test using Bus::fake() never sees it.
     */
    use Batchable, Queueable;

    public int $tries = 3;

    /**
     * Must exceed the HTTP timeout it is waiting on, or the worker kills
     * the job mid-request and the retry starts the same slow inference
     * from scratch. AI_SERVICE_ANALYZE_TIMEOUT is 300s, so this allows
     * headroom above it.
     */
    public int $timeout = 360;

    /**
     * Wait longer between each retry. If the AI service is restarting,
     * hammering it three times in three seconds helps nobody.
     */
    public array $backoff = [10, 60];

    public function __construct(public readonly int $itemImageId)
    {
    }

    public function handle(AiServiceInterface $ai): void
    {
        $image = ItemImage::with('item')->find($this->itemImageId);

        // The item may have been deleted while this sat in the queue.
        if ($image === null || $image->item === null) {
            return;
        }

        $result = $ai->analyzeImage($image);

        if ($result->isEmpty()) {
            // Nothing useful came back - storing an empty row would just
            // make the item look analysed when it was not.
            Log::info('AI analysis returned nothing.', ['item_image_id' => $image->id]);

            return;
        }

        /**
         * Written through the model for the same reason as the embedding
         * job: aiAnalysis() is a latestOfMany() relation, so its subquery
         * applies to the lookup too, and re-analysing with an older model
         * kept appending duplicate rows instead of updating the one it
         * meant to. There is no unique index here to turn that into an
         * error, so it accumulated silently.
         */
        $item = $image->item;

        ItemAiAnalysis::updateOrCreate(
            [
                'item_type' => $item->getMorphClass(),
                'item_id' => $item->getKey(),
                'model_name' => $result->modelName,
            ],
            [
                'detected_category' => $result->category,
                'detected_colour' => $result->colour,
                'detected_brand' => $result->brand,
                'detected_features' => $result->features,
                'generated_description' => $result->description,
                'model_version' => $result->modelVersion,
            ],
        );
    }
}
