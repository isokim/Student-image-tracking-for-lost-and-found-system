<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeItemImage;
use App\Jobs\GenerateItemEmbedding;

use App\Jobs\ProcessItemImage;
use App\Models\LostItem;
use Illuminate\Bus\Batchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The AI pipeline's real dispatch path.
 *
 * Every other test in this suite calls Bus::fake(), which records what
 * WOULD be dispatched without going through Laravel's batch machinery.
 * That hid a genuine bug: Bus::batch() rejects any job that does not use
 * the Batchable trait, and it only complains at dispatch time - so the
 * whole photo pipeline threw the moment it ran against a real queue,
 * while the tests stayed green.
 *
 * These tests deliberately do NOT fake the bus.
 */
class AiPipelineDispatchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @dataProvider batchedJobs
     */
    public function test_jobs_run_inside_a_batch_use_the_batchable_trait(string $job): void
    {
        $this->assertContains(
            Batchable::class,
            class_uses_recursive($job),
            "{$job} is dispatched inside Bus::batch() and must use Batchable, "
            .'or dispatching it throws at runtime.'
        );
    }

    public static function batchedJobs(): array
    {
        return [
            'analysis' => [AnalyzeItemImage::class],
            'embedding' => [GenerateItemEmbedding::class],
        ];
    }

    public function test_the_pipeline_dispatches_a_real_batch_without_throwing(): void
    {
        Storage::fake('items');
        config(['services.ai.enabled' => false]);

        $item = LostItem::factory()->create();
        $image = $item->images()->create([
            'disk' => 'items',
            'path' => 'lost_item/'.$item->id.'/photo.jpg',
            'original_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
        ]);
        Storage::disk('items')->put($image->path, 'bytes');

        /**
         * The real thing - no Bus::fake(). This is what was failing.
         *
         * QUEUE_CONNECTION is sync in tests, so the batched jobs run
         * inline and the embedding one throws because the AI service is
         * disabled. That is correct behaviour and not what is under test
         * here: what matters is that Bus::batch() ACCEPTED the jobs,
         * rather than rejecting them with "does not use the Batchable
         * trait" before any of them ran.
         */
        $batchingError = null;

        try {
            (new ProcessItemImage('lost_item', $item->id))->handle();
        } catch (\Throwable $e) {
            // Anything mentioning Batchable means dispatch itself was
            // rejected, before a single job ran. Anything else - such as
            // the embedding job failing because the AI is disabled - is
            // the pipeline working as intended.
            if (str_contains($e->getMessage(), 'Batchable')) {
                $batchingError = $e->getMessage();
            }
        }

        $this->assertNull(
            $batchingError,
            "Bus::batch() rejected a job: {$batchingError}"
        );
    }

    public function test_an_item_without_a_photo_skips_the_batch_and_is_still_processed(): void
    {
        config(['services.ai.enabled' => false]);

        $item = LostItem::factory()->create();

        (new ProcessItemImage('lost_item', $item->id))->handle();

        // No batch to create, but the item must still be stamped so
        // "has this been analysed?" has an answer.
        $this->assertDatabaseCount('job_batches', 0);
        $this->assertNotNull($item->fresh()->ai_processed_at);
    }
}
