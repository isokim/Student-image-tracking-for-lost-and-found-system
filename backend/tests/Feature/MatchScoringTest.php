<?php

namespace Tests\Feature;

use App\Enums\MatchConfidence;
use App\Models\Category;
use App\Models\FoundItem;
use App\Models\LostItem;
use App\Services\Matching\MatchScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchScoringTest extends TestCase
{
    use RefreshDatabase;

    private MatchScoringService $scorer;
    private Category $electronics;
    private Category $bags;

    protected function setUp(): void
    {
        parent::setUp();

        /**
         * Freeze the clock.
         *
         * Several of these tests assert on time-decay boundaries built
         * from now(). If the wall clock ticks over a day or hour boundary
         * between the factory call and the assertion, the expected score
         * shifts and the test fails for a reason that has nothing to do
         * with the scoring rules.
         */
        $this->freezeTime();

        $this->scorer = app(MatchScoringService::class);
        $this->electronics = Category::factory()->create(['slug' => 'electronics']);
        $this->bags = Category::factory()->create(['slug' => 'bags']);
    }

    private function pair(array $lostAttrs = [], array $foundAttrs = []): array
    {
        $lost = LostItem::factory()->create(array_merge([
            'category_id' => $this->electronics->id,
            'location_text' => 'Near the library entrance',
            'title' => 'Black iPhone 13',
            'description' => 'Black iPhone with a cracked corner',
            'colour' => 'black',
            'lost_at' => now()->subDays(2),
        ], $lostAttrs));

        $found = FoundItem::factory()->create(array_merge([
            'category_id' => $this->electronics->id,
            'location_text' => 'Near the library entrance',
            'title' => 'Black iPhone 13',
            'description' => 'Black iPhone with a cracked corner',
            'colour' => 'black',
            'found_at' => now()->subDay(),
        ], $foundAttrs));

        return [$lost, $found];
    }

    public function test_the_configured_weights_sum_to_one(): void
    {
        $this->assertEqualsWithDelta(1.0, array_sum(config('matching.weights')), 0.0001);
    }

    public function test_the_service_refuses_to_run_with_weights_that_do_not_sum_to_one(): void
    {
        // A silent rescale would put every score on a different scale and
        // quietly break the thresholds, so this must fail loudly.
        config(['matching.weights' => ['image' => 0.9, 'text' => 0.9, 'category' => 0.0,
            'colour' => 0.0, 'location' => 0.0, 'time' => 0.0]]);

        $this->expectException(\InvalidArgumentException::class);

        new MatchScoringService;
    }

    public function test_an_identical_pair_with_a_perfect_image_score_is_near_one(): void
    {
        [$lost, $found] = $this->pair();

        $score = $this->scorer->score($lost, $found, imageScore: 1.0);

        $this->assertGreaterThan(0.9, $score->overall);
        $this->assertSame(MatchConfidence::Likely, $score->confidence());
        $this->assertTrue($score->meetsThreshold());
    }

    public function test_a_completely_unrelated_pair_scores_below_the_threshold(): void
    {
        [$lost, $found] = $this->pair([], [
            'category_id' => $this->bags->id,
            'location_text' => 'Student cafeteria',
            'title' => 'Red umbrella',
            'description' => 'Small red folding umbrella',
            'colour' => 'red',
            'found_at' => now()->subDays(25),
        ]);

        $score = $this->scorer->score($lost, $found, imageScore: 0.05);

        $this->assertLessThan(config('matching.thresholds.minimum_score'), $score->overall);
        $this->assertFalse($score->meetsThreshold());
    }

    /**
     * The headline guarantee: the system never claims certainty.
     */
    public function test_confidence_never_exceeds_likely_even_for_a_perfect_score(): void
    {
        [$lost, $found] = $this->pair();

        $score = $this->scorer->score($lost, $found, imageScore: 1.0);

        $this->assertContains($score->confidence(), [MatchConfidence::Possible, MatchConfidence::Likely]);
        $this->assertNotSame('guaranteed', $score->confidence()->value);
    }

    public function test_a_moderate_score_is_only_possible_not_likely(): void
    {
        [$lost, $found] = $this->pair([], [
            'title' => 'Black phone',
            'description' => 'A dark phone handed in',
            'location_text' => 'Student cafeteria',
            'found_at' => now()->subDays(10),
        ]);

        $score = $this->scorer->score($lost, $found, imageScore: 0.55);

        $this->assertSame(MatchConfidence::Possible, $score->confidence());
    }

    // -----------------------------------------------------------------
    // Photoless reports
    // -----------------------------------------------------------------

    public function test_a_photoless_pair_can_still_score_highly(): void
    {
        [$lost, $found] = $this->pair();

        // Without redistribution this could never exceed 0.50, and every
        // photoless report would look like a bad match.
        $score = $this->scorer->score($lost, $found, imageScore: null);

        $this->assertNull($score->image);
        $this->assertGreaterThan(0.75, $score->overall);
        $this->assertTrue($score->meetsThreshold());
    }

    public function test_a_photoless_score_still_lands_between_zero_and_one(): void
    {
        [$lost, $found] = $this->pair([], [
            'category_id' => $this->bags->id,
            'title' => 'Red umbrella',
            'description' => 'Small red folding umbrella',
            'colour' => 'red',
            'location_text' => 'Student cafeteria',
        ]);

        $score = $this->scorer->score($lost, $found, imageScore: null);

        $this->assertGreaterThanOrEqual(0.0, $score->overall);
        $this->assertLessThanOrEqual(1.0, $score->overall);
    }

    // -----------------------------------------------------------------
    // Individual signals
    // -----------------------------------------------------------------

    public function test_matching_categories_score_higher_than_differing_ones(): void
    {
        [$lostA, $foundA] = $this->pair();
        [$lostB, $foundB] = $this->pair([], ['category_id' => $this->bags->id]);

        $this->assertSame(1.0, $this->scorer->score($lostA, $foundA)->category);
        $this->assertSame(0.0, $this->scorer->score($lostB, $foundB)->category);
    }

    /**
     * The locations list is gone, so the typed text is the only location
     * signal left. Identical places must still score above different
     * ones, or the component is dead weight in the weighted sum.
     */
    public function test_the_same_typed_location_scores_higher_than_a_different_one(): void
    {
        [$lostSame, $foundSame] = $this->pair();
        [$lostElsewhere, $foundElsewhere] = $this->pair([], ['location_text' => 'Annex Building']);

        $this->assertSame(1.0, $this->scorer->score($lostSame, $foundSame)->location);
        $this->assertLessThan(
            $this->scorer->score($lostSame, $foundSame)->location,
            $this->scorer->score($lostElsewhere, $foundElsewhere)->location,
        );
    }

    /**
     * A missing location is not evidence against a match, and it must not
     * collapse to zero either - most reports have one, but not all.
     */
    public function test_a_missing_location_is_neutral_rather_than_counted_against_the_match(): void
    {
        [$lost, $found] = $this->pair([], ['location_text' => null]);

        $this->assertSame(0.5, $this->scorer->score($lost, $found)->location);
    }

    public function test_a_partial_colour_name_still_scores_well(): void
    {
        [$lost, $found] = $this->pair(['colour' => 'blue'], ['colour' => 'navy blue']);

        $this->assertGreaterThanOrEqual(0.75, $this->scorer->score($lost, $found)->colour);
    }

    public function test_an_item_found_before_it_was_lost_scores_zero_on_time(): void
    {
        [$lost, $found] = $this->pair(
            ['lost_at' => now()->subDays(2)],
            ['found_at' => now()->subDays(10)],
        );

        $this->assertSame(0.0, $this->scorer->score($lost, $found)->time);
    }

    public function test_time_relevance_decays_the_longer_the_gap(): void
    {
        [$lostSoon, $foundSoon] = $this->pair(
            ['lost_at' => now()->subDays(10)],
            ['found_at' => now()->subDays(9)],
        );
        [$lostLate, $foundLate] = $this->pair(
            ['lost_at' => now()->subDays(40)],
            ['found_at' => now()->subDays(15)],
        );

        $this->assertGreaterThan(
            $this->scorer->score($lostLate, $foundLate)->time,
            $this->scorer->score($lostSoon, $foundSoon)->time,
        );
    }

    public function test_an_item_found_beyond_the_time_window_scores_zero(): void
    {
        [$lost, $found] = $this->pair(
            ['lost_at' => now()->subDays(90)],
            ['found_at' => now()->subDays(30)],
        );

        $this->assertSame(0.0, $this->scorer->score($lost, $found)->time);
    }

    public function test_an_out_of_range_image_score_is_clamped(): void
    {
        [$lost, $found] = $this->pair();

        // A misbehaving AI service must not be able to push the overall
        // score outside 0..1.
        $this->assertSame(1.0, $this->scorer->score($lost, $found, 5.0)->image);
        $this->assertSame(0.0, $this->scorer->score($lost, $found, -3.0)->image);
        $this->assertLessThanOrEqual(1.0, $this->scorer->score($lost, $found, 5.0)->overall);
    }

    public function test_every_component_is_recorded_for_the_ui_to_explain_the_match(): void
    {
        [$lost, $found] = $this->pair();

        $attributes = $this->scorer->score($lost, $found, 0.8)->toAttributes();

        foreach (['image_score', 'text_score', 'category_score', 'colour_score',
            'location_score', 'time_score', 'overall_score',
            'confidence', 'scoring_version'] as $key) {
            $this->assertArrayHasKey($key, $attributes);
        }
    }
}
