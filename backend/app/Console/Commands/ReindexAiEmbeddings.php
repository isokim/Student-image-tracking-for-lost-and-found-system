<?php

namespace App\Console\Commands;

use App\Jobs\ProcessItemImage;
use App\Models\FoundItem;
use App\Models\ItemEmbedding;
use App\Models\LostItem;
use Illuminate\Console\Command;

/**
 * Rebuilds the AI service's view of the world.
 *
 * Why this is needed: MySQL and the vector index are separate stores.
 * `migrate:fresh` empties the database but the AI service keeps every
 * vector it was ever given, so similarity search starts returning ids
 * for items that no longer exist - which Laravel then filters out,
 * leaving searches quietly returning nothing.
 *
 * The same applies after switching embedding backends: vectors from two
 * different models are not comparable, so the old ones must go.
 *
 *   php artisan ai:reindex
 *
 * Delete ai-service/storage/ first for a completely clean rebuild; this
 * command only re-queues what Laravel currently knows about.
 */
class ReindexAiEmbeddings extends Command
{
    protected $signature = 'ai:reindex
                            {--fresh : Drop the local embedding references before re-queueing}';

    protected $description = 'Re-queue AI analysis and embeddings for every item that has a photo.';

    public function handle(): int
    {
        if (! config('services.ai.enabled')) {
            $this->warn('AI_SERVICE_ENABLED is false - nothing to do.');

            return self::SUCCESS;
        }

        if ($this->option('fresh')) {
            $dropped = ItemEmbedding::query()->delete();
            $this->info("Dropped {$dropped} embedding reference(s).");
            $this->line('Remember to delete ai-service/storage/ as well, or stale vectors remain.');
        }

        $queued = 0;

        foreach ([LostItem::class, FoundItem::class] as $model) {
            // whereHas rather than loading the images: this can be a lot
            // of rows, and the job only needs the id.
            $model::query()
                ->whereHas('images')
                ->select('id')
                ->chunkById(100, function ($items) use (&$queued, $model) {
                    foreach ($items as $item) {
                        ProcessItemImage::dispatch($item->getMorphClass(), $item->id);
                        $queued++;
                    }
                });
        }

        $this->info("Queued {$queued} item(s) for re-analysis.");
        $this->line('Run `php artisan queue:work` to process them.');

        return self::SUCCESS;
    }
}
