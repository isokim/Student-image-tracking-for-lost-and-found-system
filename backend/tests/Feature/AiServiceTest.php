<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeItemImage;
use App\Jobs\FindPotentialMatches;
use App\Jobs\GenerateItemEmbedding;
use App\Models\Category;
use App\Models\FoundItem;
use App\Models\ItemImage;
use App\Models\LostItem;
use App\Models\User;
use App\Services\Ai\AiServiceInterface;
use App\Services\Ai\AiServiceUnavailableException;
use App\Services\Ai\FastApiAiService;
use App\Services\Ai\NullAiService;
use App\Services\Matching\MatchFinderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The AI abstraction.
 *
 * The point of these tests is the boundary, not the model: Laravel must
 * work with the AI service present, absent, or broken, and swapping the
 * implementation must not require changing anything else.
 */
class AiServiceTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Binding and relocation
    // -----------------------------------------------------------------

    public function test_the_container_resolves_the_fastapi_implementation_when_enabled(): void
    {
        config(['services.ai.enabled' => true]);
        $this->app->forgetInstance(AiServiceInterface::class);

        $this->assertInstanceOf(FastApiAiService::class, app(AiServiceInterface::class));
    }

    public function test_the_container_falls_back_to_the_null_implementation_when_disabled(): void
    {
        // So the whole system runs without Python or Ollama installed.
        config(['services.ai.enabled' => false]);
        $this->app->forgetInstance(AiServiceInterface::class);

        $this->assertInstanceOf(NullAiService::class, app(AiServiceInterface::class));
    }

    /**
     * The relocation guarantee: moving the AI service to the cloud is a
     * change of URL, not a change of code.
     */
    public function test_pointing_the_service_at_a_different_host_requires_no_code_change(): void
    {
        config([
            'services.ai.enabled' => true,
            'services.ai.url' => 'https://ai.example-cloud.test',
        ]);
        $this->app->forgetInstance(AiServiceInterface::class);

        Http::fake([
            'ai.example-cloud.test/*' => Http::response([
                'reference' => 'vec-123',
                'model_name' => 'siglip',
                'model_version' => '1.0',
                'dimensions' => 768,
            ]),
        ]);

        $result = app(AiServiceInterface::class)->embedRawImage('fake-bytes', 'image/jpeg');

        $this->assertSame('vec-123', $result->reference);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://ai.example-cloud.test'));
    }

    public function test_laravel_never_receives_or_stores_the_vector_itself(): void
    {
        config(['services.ai.enabled' => true]);
        $this->app->forgetInstance(AiServiceInterface::class);

        Http::fake([
            '*' => Http::response([
                'reference' => 'vec-abc',
                'model_name' => 'siglip',
                'model_version' => '1.0',
                'dimensions' => 768,
                // Even if the service volunteered the raw vector...
                'vector' => array_fill(0, 768, 0.1),
            ]),
        ]);

        $result = app(AiServiceInterface::class)->embedRawImage('bytes', 'image/jpeg');

        // ...the value object has nowhere to put it. FAISS owns vectors;
        // MySQL stores only the reference.
        $this->assertObjectNotHasProperty('vector', $result);
        $this->assertSame('vec-abc', $result->reference);
    }

    // -----------------------------------------------------------------
    // Graceful degradation
    // -----------------------------------------------------------------

    public function test_an_unreachable_ai_service_does_not_break_image_analysis(): void
    {
        config(['services.ai.enabled' => true]);
        $this->app->forgetInstance(AiServiceInterface::class);
        Storage::fake('items');

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

        $item = LostItem::factory()->create();
        $image = $item->images()->create([
            'disk' => 'items', 'path' => 'x.jpg', 'original_name' => 'x.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);
        Storage::disk('items')->put('x.jpg', 'bytes');

        // Returns an empty result rather than throwing: a student filing
        // a report must not see an error because Ollama is not running.
        $result = app(AiServiceInterface::class)->analyzeImage($image->fresh());

        $this->assertTrue($result->isEmpty());
    }

    public function test_an_unreachable_ai_service_returns_no_similar_items_rather_than_failing(): void
    {
        config(['services.ai.enabled' => true]);
        $this->app->forgetInstance(AiServiceInterface::class);

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

        $this->assertSame([], app(AiServiceInterface::class)->findSimilarItems('ref', 'found_item'));
    }

    /**
     * The exception to the degrade-quietly rule.
     */
    public function test_image_search_reports_unavailability_instead_of_pretending_there_are_no_results(): void
    {
        // "No results" and "search is broken" mean very different things
        // to someone looking for their missing laptop.
        $this->expectException(AiServiceUnavailableException::class);

        (new NullAiService)->embedRawImage('bytes', 'image/jpeg');
    }

    public function test_the_image_search_endpoint_returns_503_when_the_ai_service_is_off(): void
    {
        config(['services.ai.enabled' => false]);
        $this->app->forgetInstance(AiServiceInterface::class);

        $this->actingAs(User::factory()->student()->create())
            ->post('/api/search/image', [
                'image' => UploadedFile::fake()->image('query.jpg'),
            ])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Image search is unavailable at the moment. Try a keyword search instead.');
    }

    /**
     * The headline resilience claim: matching still works without AI.
     */
    public function test_matching_still_finds_items_by_text_when_the_ai_service_is_disabled(): void
    {
        config(['services.ai.enabled' => false]);
        $this->app->forgetInstance(AiServiceInterface::class);

        $category = Category::factory()->electronics()->create();
        $student = User::factory()->student()->create();

        $lost = LostItem::factory()->create([
            'user_id' => $student->id,
            'category_id' => $category->id,
            'location_text' => 'Near the library entrance',
            'title' => 'Black iPhone 13',
            'description' => 'Black iPhone with a cracked corner',
            'colour' => 'black',
            'lost_at' => now()->subDays(2),
        ]);

        FoundItem::factory()->verified()->create([
            'category_id' => $category->id,
            'location_text' => 'Near the library entrance',
            'title' => 'Black iPhone 13',
            'description' => 'Black iPhone with a cracked corner',
            'colour' => 'black',
            'found_at' => now()->subDay(),
        ]);

        $matches = app(MatchFinderService::class)->findForLostItem($lost);

        $this->assertCount(1, $matches, 'text and metadata matching must work without the AI service');

        // And with no photo compared, the image component is null rather
        // than a fabricated zero.
        $this->assertNull($matches[0]->image_score);
    }

    // -----------------------------------------------------------------
    // Work happens on the queue
    // -----------------------------------------------------------------

    public function test_an_embedding_failure_is_thrown_so_the_job_retries(): void
    {
        config(['services.ai.enabled' => false]);
        $this->app->forgetInstance(AiServiceInterface::class);
        Storage::fake('items');

        $item = LostItem::factory()->create();
        $image = $item->images()->create([
            'disk' => 'items', 'path' => 'x.jpg', 'original_name' => 'x.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        // Unlike analysis, a missing embedding means the item is invisible
        // to image search forever - so this must fail loudly and retry.
        $this->expectException(AiServiceUnavailableException::class);

        (new GenerateItemEmbedding($image->id))->handle(app(AiServiceInterface::class));
    }

    /**
     * Regression: item_type and item_id were not fillable on
     * ItemEmbedding, so updateOrCreate() stripped the very columns it was
     * matching on and the INSERT failed with "Field 'item_type' doesn't
     * have a default value". The job failed silently on the queue while
     * every mocked test still passed.
     */
    public function test_a_successful_embedding_is_actually_persisted(): void
    {
        config(['services.ai.enabled' => true]);
        $this->app->forgetInstance(AiServiceInterface::class);
        Storage::fake('items');

        Http::fake([
            '*' => Http::response([
                'reference' => 'vec_test_reference',
                'model_name' => 'test-model',
                'model_version' => '1.0',
                'dimensions' => 512,
            ]),
        ]);

        $item = LostItem::factory()->create();
        $image = $item->images()->create([
            'disk' => 'items', 'path' => 'x.jpg', 'original_name' => 'x.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);
        Storage::disk('items')->put('x.jpg', 'bytes');

        (new GenerateItemEmbedding($image->id))->handle(app(AiServiceInterface::class));

        $this->assertDatabaseHas('item_embeddings', [
            'item_type' => 'lost_item',
            'item_id' => $item->id,
            'embedding_reference' => 'vec_test_reference',
            'model_name' => 'test-model',
        ]);
    }

    public function test_re_embedding_the_same_item_updates_rather_than_duplicating(): void
    {
        config(['services.ai.enabled' => true]);
        $this->app->forgetInstance(AiServiceInterface::class);
        Storage::fake('items');

        Http::fake([
            '*' => Http::sequence()
                ->push(['reference' => 'vec_first', 'model_name' => 'test-model', 'model_version' => '1.0', 'dimensions' => 512])
                ->push(['reference' => 'vec_second', 'model_name' => 'test-model', 'model_version' => '1.0', 'dimensions' => 512]),
        ]);

        $item = LostItem::factory()->create();
        $image = $item->images()->create([
            'disk' => 'items', 'path' => 'x.jpg', 'original_name' => 'x.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);
        Storage::disk('items')->put('x.jpg', 'bytes');

        $job = new GenerateItemEmbedding($image->id);
        $job->handle(app(AiServiceInterface::class));
        $job->handle(app(AiServiceInterface::class));

        // One row, updated - not two. The unique index would reject a
        // second insert anyway, failing the job.
        $this->assertDatabaseCount('item_embeddings', 1);
        $this->assertDatabaseHas('item_embeddings', ['embedding_reference' => 'vec_second']);
    }

    public function test_the_analysis_job_stores_nothing_when_the_model_returns_nothing(): void
    {
        config(['services.ai.enabled' => false]);
        $this->app->forgetInstance(AiServiceInterface::class);
        Storage::fake('items');

        $item = LostItem::factory()->create();
        $image = $item->images()->create([
            'disk' => 'items', 'path' => 'x.jpg', 'original_name' => 'x.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        (new AnalyzeItemImage($image->id))->handle(app(AiServiceInterface::class));

        // An empty row would make the item look analysed when it was not.
        $this->assertDatabaseCount('item_ai_analysis', 0);
    }

    public function test_jobs_tolerate_the_item_being_deleted_while_queued(): void
    {
        config(['services.ai.enabled' => false]);
        $this->app->forgetInstance(AiServiceInterface::class);

        // A job holding an id for a row that no longer exists must be a
        // no-op, not a crash that fills the failed_jobs table.
        (new AnalyzeItemImage(99999))->handle(app(AiServiceInterface::class));
        (new FindPotentialMatches('lost_item', 99999))->handle(app(MatchFinderService::class));

        $this->assertTrue(true);
    }

    public function test_python_is_never_given_authority_over_who_sees_what(): void
    {
        config(['services.ai.enabled' => true, 'services.ai.url' => 'http://127.0.0.1:8001']);
        $this->app->forgetInstance(AiServiceInterface::class);

        $category = Category::factory()->electronics()->create();

        // An item the AI service will happily point at, but which staff
        // have NOT verified.
        $unverified = FoundItem::factory()->create([
            'category_id' => $category->id,
            'location_text' => 'Near the library entrance',
            'title' => 'Black iPhone 13',
        ]);

        Http::fake([
            '*/embed' => Http::response([
                'reference' => 'vec-1', 'model_name' => 'siglip', 'model_version' => '1.0',
            ]),
            '*/similar' => Http::response([
                'results' => [
                    ['item_type' => 'found_item', 'item_id' => $unverified->id, 'score' => 0.99],
                ],
            ]),
        ]);

        $response = $this->actingAs(User::factory()->student()->create())
            ->post('/api/search/image', ['image' => UploadedFile::fake()->image('query.jpg')])
            ->assertOk();

        /**
         * The AI service returned the item with a near-perfect score, and
         * Laravel still refused to show it. Ids from Python are a
         * shortlist to re-check, never a result set.
         */
        $this->assertCount(0, $response->json());
    }
}
