<?php

namespace App\Models\Concerns;

use App\Models\ItemAiAnalysis;
use App\Models\ItemEmbedding;
use App\Models\ItemImage;
use App\Models\ItemStatusHistory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Shared by LostItem and FoundItem. Both are "items" as far as images,
 * AI analysis, embeddings and status history are concerned, so those
 * relationships live here instead of being duplicated in both models.
 */
trait HasItemRelations
{
    public function images(): MorphMany
    {
        return $this->morphMany(ItemImage::class, 'item');
    }

    /** The UI shows a single photo per item; this is the first one uploaded. */
    public function primaryImage(): MorphOne
    {
        return $this->morphOne(ItemImage::class, 'item')->oldestOfMany();
    }

    public function aiAnalysis(): MorphOne
    {
        return $this->morphOne(ItemAiAnalysis::class, 'item')->latestOfMany();
    }

    public function embedding(): MorphOne
    {
        return $this->morphOne(ItemEmbedding::class, 'item')->latestOfMany();
    }

    public function statusHistory(): MorphMany
    {
        return $this->morphMany(ItemStatusHistory::class, 'item')->latest();
    }

    /** True once the AI pipeline has finished with this item. */
    public function isAiProcessed(): bool
    {
        return $this->ai_processed_at !== null;
    }
}
