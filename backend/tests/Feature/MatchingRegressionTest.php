<?php

namespace Tests\Feature;

use App\Jobs\FindPotentialMatches;
use App\Jobs\ProcessItemImage;
use App\Models\Category;
use App\Models\FoundItem;
use App\Models\ItemMatch;
use App\Models\LostItem;
use App\Models\User;
use App\Notifications\PossibleMatchFound;
use App\Services\Matching\MatchFinderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Regressions in the matching engine.
 *
 * These deliberately create MORE THAN ONE candidate. Laravel only arms
 * preventLazyLoading on result sets with more than one row, so a suite
 * built entirely on single-model fetches cannot see a missing
 * eager-load - which is exactly how the bug below survived 234 passing
 * tests.
 */
class MatchingRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();

        $this->category = Category::factory()->electronics()->create();
    }

    private function lostItem(User $owner, array $overrides = []): LostItem
    {
        return LostItem::factory()->create(array_merge([
            'user_id' => $owner->id,
            'category_id' => $this->category->id,
            'location_text' => 'Near the library entrance',
            'title' => 'Black iPhone 13',
            'description' => 'Black iPhone with a cracked corner',
            'colour' => 'black',
            'lost_at' => now()->subDays(2),
        ], $overrides));
    }

    private function foundItem(array $overrides = []): FoundItem
    {
        return FoundItem::factory()->verified()->create(array_merge([
            'category_id' => $this->category->id,
            'location_text' => 'Near the library entrance',
            'title' => 'Black iPhone 13',
            'description' => 'Black iPhone with a cracked corner',
            'colour' => 'black',
            'found_at' => now()->subDay(),
        ], $overrides));
    }

    /**
     * The bug: candidateLostItems() did not eager-load `user`, but
     * afterMatching() calls $lost->user->notify(). With two candidates
     * the second one threw, so the first student was notified and
     * everyone after them silently was not.
     */
    public function test_every_matching_student_is_notified_not_just_the_first(): void
    {
        Notification::fake();

        $studentA = User::factory()->student()->create();
        $studentB = User::factory()->student()->create();
        $studentC = User::factory()->student()->create();

        $this->lostItem($studentA);
        $this->lostItem($studentB);
        $this->lostItem($studentC);

        $found = $this->foundItem();

        $matches = app(MatchFinderService::class)->findForFoundItem($found);

        $this->assertCount(3, $matches, 'all three open reports should match');

        Notification::assertSentTo($studentA, PossibleMatchFound::class);
        Notification::assertSentTo($studentB, PossibleMatchFound::class);
        Notification::assertSentTo($studentC, PossibleMatchFound::class);
    }

    public function test_scoring_many_found_candidates_does_not_trigger_lazy_loading(): void
    {
        Notification::fake();

        $student = User::factory()->student()->create();
        $lost = $this->lostItem($student);

        // Several candidates, so preventLazyLoading is actually armed.
        $this->foundItem();
        $this->foundItem(['title' => 'Black iPhone 12']);
        $this->foundItem(['title' => 'Dark Apple phone']);

        $matches = app(MatchFinderService::class)->findForLostItem($lost);

        $this->assertGreaterThanOrEqual(1, count($matches));
    }

    /**
     * The bug: ProcessItemImage was only dispatched when the item had a
     * photo, and it is the only thing that dispatches FindPotentialMatches
     * for a lost report. So a photoless report was never matched at all -
     * silently disabling the core feature for anyone who did not upload a
     * picture.
     */
    public function test_a_lost_report_filed_without_a_photo_still_enters_the_matching_pipeline(): void
    {
        Bus::fake();

        $student = User::factory()->student()->create();

        $this->actingAs($student)->postJson('/api/lost-items', [
            'title' => 'Black iPhone 13',
            'category' => 'electronics',
            'description' => 'Black iPhone with a cracked top-right corner.',
            'location' => 'Near the library entrance',
            'lostAt' => now()->subDays(2)->toIso8601String(),
        ])->assertCreated();

        Bus::assertDispatched(ProcessItemImage::class);
    }

    public function test_the_pipeline_reaches_matching_for_a_photoless_item(): void
    {
        Bus::fake([FindPotentialMatches::class]);

        $student = User::factory()->student()->create();
        $lost = $this->lostItem($student);

        // No image attached, so the batch is skipped and matching runs
        // directly.
        (new ProcessItemImage('lost_item', $lost->id))->handle();

        Bus::assertDispatched(FindPotentialMatches::class);
        $this->assertNotNull($lost->fresh()->ai_processed_at);
    }

    public function test_a_photoless_report_actually_produces_a_match(): void
    {
        Notification::fake();

        $student = User::factory()->student()->create();
        $lost = $this->lostItem($student);
        $this->foundItem();

        $matches = app(MatchFinderService::class)->findForLostItem($lost);

        // Text, category, colour, location and time are plenty.
        $this->assertCount(1, $matches);
        $this->assertNull($matches[0]->image_score);
    }

    /**
     * The bug: candidate queries used LIMIT with no ORDER BY, so MySQL
     * returned an arbitrary (in practice oldest-first) slice. With more
     * candidates than the limit, the most recent items - the ones most
     * likely to be the match - were never scored.
     */
    public function test_the_most_recent_candidates_are_scored_first(): void
    {
        Notification::fake();

        $student = User::factory()->student()->create();
        $lost = $this->lostItem($student, ['lost_at' => now()->subDays(20)]);

        // An old one and a recent one, both inside the window.
        $this->foundItem(['found_at' => now()->subDays(19), 'title' => 'Older black iPhone']);
        $recent = $this->foundItem(['found_at' => now()->subHours(2), 'title' => 'Black iPhone 13']);

        $matches = app(MatchFinderService::class)->findForLostItem($lost);

        $matchedIds = array_map(fn ($m) => $m->found_item_id, $matches);

        $this->assertContains($recent->id, $matchedIds, 'the newest candidate must be scored');
    }

    /**
     * The bug: matching.candidates.max_stored_per_item was config nobody
     * read, so a common description could accumulate an unbounded list of
     * weak suggestions and bury the good ones.
     */
    public function test_the_stored_match_list_is_capped(): void
    {
        Notification::fake();
        config(['matching.candidates.max_stored_per_item' => 3]);

        $student = User::factory()->student()->create();
        $lost = $this->lostItem($student);

        for ($i = 0; $i < 6; $i++) {
            $this->foundItem(['title' => "Black iPhone 13 number {$i}"]);
        }

        app(MatchFinderService::class)->findForLostItem($lost);

        $this->assertLessThanOrEqual(
            3,
            ItemMatch::where('lost_item_id', $lost->id)->count(),
        );
    }

    public function test_rerunning_the_matcher_does_not_resurrect_a_dismissed_suggestion(): void
    {
        Notification::fake();

        $student = User::factory()->student()->create();
        $lost = $this->lostItem($student);
        $found = $this->foundItem();

        $finder = app(MatchFinderService::class);
        $finder->findForLostItem($lost);

        $match = ItemMatch::firstOrFail();
        $match->status = \App\Enums\MatchStatus::Dismissed;
        $match->save();

        // A rescoring pass updates the score but must leave the student's
        // decision alone.
        $finder->findForLostItem($lost->fresh());

        $this->assertSame(\App\Enums\MatchStatus::Dismissed, $match->fresh()->status);
    }
}
