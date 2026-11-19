<?php

namespace Tests\Feature;

use App\Console\Commands\CleanupWeakMatches;
use App\Enums\LostItemStatus;
use App\Enums\MatchStatus;
use App\Models\ItemMatch;
use App\Models\LostItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanupWeakMatchesTest extends TestCase
{
    use RefreshDatabase;

    private ItemMatch $weakNoPhoto;

    private ItemMatch $weakLowPhoto;

    private ItemMatch $strongText;

    private ItemMatch $strongPhoto;

    private ItemMatch $weakButDismissed;

    private ItemMatch $weakButClaimed;

    private LostItem $onlyWeak;

    private LostItem $weakAndStrong;

    private LostItem $onlyDismissed;

    private LostItem $activeItem;

    protected function setUp(): void
    {
        parent::setUp();

        config(['matching.thresholds.minimum_image_score' => 0.75]);

        // Matched, but only weak matches -> should go back to active.
        $this->onlyWeak = LostItem::factory()->create(['status' => LostItemStatus::Matched]);
        $this->weakNoPhoto = $this->match($this->onlyWeak, ['text_score' => 0, 'image_score' => null]);
        $this->weakLowPhoto = $this->match($this->onlyWeak, ['text_score' => 0, 'image_score' => 0.60]);

        // Matched, with one weak and one strong -> stays matched.
        $this->weakAndStrong = LostItem::factory()->create(['status' => LostItemStatus::Matched]);
        $this->match($this->weakAndStrong, ['text_score' => 0, 'image_score' => 0.10]);
        $this->strongText = $this->match($this->weakAndStrong, ['text_score' => 0.30, 'image_score' => null]);
        $this->strongPhoto = $this->match($this->weakAndStrong, ['text_score' => 0, 'image_score' => 0.90]);

        // Matched, but every match was dismissed (stuck badge) -> active.
        // The weak dismissed match itself is history and is kept.
        $this->onlyDismissed = LostItem::factory()->create(['status' => LostItemStatus::Matched]);
        $this->weakButDismissed = $this->match($this->onlyDismissed, [
            'text_score' => 0, 'image_score' => null, 'status' => MatchStatus::Dismissed, 'dismissed_at' => now(),
        ]);

        // Already active, with a weak match a claim was started from -> untouched.
        $this->activeItem = LostItem::factory()->create(['status' => LostItemStatus::Active]);
        $this->weakButClaimed = $this->match($this->activeItem, [
            'text_score' => 0, 'image_score' => null, 'status' => MatchStatus::ClaimStarted,
        ]);
    }

    private function match(LostItem $lost, array $attributes): ItemMatch
    {
        return ItemMatch::factory()->create(['lost_item_id' => $lost->id] + $attributes);
    }

    public function test_the_dry_run_reports_counts_and_changes_nothing(): void
    {
        $this->artisan('matches:cleanup')
            ->expectsOutputToContain('Weak pending matches to delete: 3')
            ->expectsOutputToContain('Lost items to move from matched back to active: 2')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(7, ItemMatch::count());
        $this->assertSame(LostItemStatus::Matched, $this->onlyWeak->fresh()->status);
        $this->assertSame(LostItemStatus::Matched, $this->onlyDismissed->fresh()->status);
    }

    public function test_force_deletes_weak_pending_matches_and_resets_stuck_items(): void
    {
        $this->artisan('matches:cleanup', ['--force' => true])->assertSuccessful();

        $this->assertModelMissing($this->weakNoPhoto);
        $this->assertModelMissing($this->weakLowPhoto);
        $this->assertModelExists($this->strongText);
        $this->assertModelExists($this->strongPhoto);
        $this->assertModelExists($this->weakButDismissed);
        $this->assertModelExists($this->weakButClaimed);
        $this->assertSame(4, ItemMatch::count());

        $this->assertSame(LostItemStatus::Active, $this->onlyWeak->fresh()->status);
        $this->assertSame(LostItemStatus::Active, $this->onlyDismissed->fresh()->status);
        $this->assertSame(LostItemStatus::Matched, $this->weakAndStrong->fresh()->status);
        $this->assertSame(LostItemStatus::Active, $this->activeItem->fresh()->status);

        // Recorded through ItemStatusRecorder, with a note.
        $this->assertDatabaseHas('item_status_history', [
            'item_id' => $this->onlyWeak->id,
            'from_status' => 'matched',
            'to_status' => 'active',
            'note' => CleanupWeakMatches::NOTE,
        ]);
    }
}
