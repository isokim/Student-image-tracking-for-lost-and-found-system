<?php

namespace App\Jobs;

use App\Models\FoundItem;
use App\Models\LostItem;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * The AI pipeline for one item, in order:
 *
 *   analyse + embed   (independent of each other, so run together)
 *          |
 *   find matches      (needs the embedding to exist first)
 *
 * Expressed as a chained batch rather than three separate dispatches,
 * because matching is only worth running once the embedding is in place
 * - otherwise the first pass would silently have no image signal.
 *
 * ai_processed_at is stamped at the end, so "has this been analysed?"
 * has a real answer rather than being inferred from whether rows exist.
 */
class ProcessItemImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // the inner jobs do their own retrying

    public function __construct(
        public readonly string $itemType,
        public readonly int $itemId,
    ) {
    }

    public function handle(): void
    {
        $item = $this->resolveItem();

        if ($item === null) {
            return;
        }

        $image = $item->primaryImage;

        if ($image === null) {
            // No photo: skip straight to text-only matching.
            FindPotentialMatches::dispatch($this->itemType, $this->itemId);
            $item->forceFill(['ai_processed_at' => now()])->save();

            return;
        }

        $itemType = $this->itemType;
        $itemId = $this->itemId;

        Bus::batch([
            new AnalyzeItemImage($image->id),
            new GenerateItemEmbedding($image->id),
        ])
            ->then(function (Batch $batch) use ($itemType, $itemId) {
                // Both succeeded - now there is an embedding to match on.
                FindPotentialMatches::dispatch($itemType, $itemId);
            })
            ->catch(function (Batch $batch, Throwable $e) use ($itemType, $itemId) {
                /**
                 * Even if the AI failed, still run matching. Text,
                 * category, location and time matching all work without
                 * an embedding, so a student whose photo could not be
                 * processed still gets suggestions.
                 */
                FindPotentialMatches::dispatch($itemType, $itemId);
            })
            ->finally(function () use ($itemType, $itemId) {
                $item = $itemType === 'lost_item'
                    ? LostItem::find($itemId)
                    : FoundItem::find($itemId);

                $item?->forceFill(['ai_processed_at' => now()])->save();
            })
            ->name("AI pipeline for {$this->itemType} #{$this->itemId}")
            ->dispatch();
    }

    private function resolveItem(): LostItem|FoundItem|null
    {
        return $this->itemType === 'lost_item'
            ? LostItem::with('primaryImage')->find($this->itemId)
            : FoundItem::with('primaryImage')->find($this->itemId);
    }
}
