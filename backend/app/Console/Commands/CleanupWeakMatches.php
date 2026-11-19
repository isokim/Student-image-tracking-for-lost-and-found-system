<?php

namespace App\Console\Commands;

use App\Enums\LostItemStatus;
use App\Enums\MatchStatus;
use App\Models\ItemMatch;
use App\Models\LostItem;
use App\Services\ItemStatusRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One-off data repair after the matching rules were tightened.
 *
 * Older scoring let a pair through on visual similarity alone, even when
 * the photos were only loosely alike and the descriptions shared no
 * words. Those suggestions are still sitting in students' match lists,
 * and the lost reports they touched still wear a "Possible match" badge.
 *
 * This command:
 *   (a) deletes PENDING matches with no description-word evidence
 *       (text_score 0 or null) whose image_score is null or below
 *       matching.thresholds.minimum_image_score - i.e. pairs the current
 *       rules would not create. Dismissed matches and matches a claim was
 *       started from are left alone: they are part of someone's history.
 *   (b) moves lost reports that are "matched" but have no non-dismissed
 *       match left back to "active", through ItemStatusRecorder so the
 *       item timeline shows why.
 *
 *   php artisan matches:cleanup           # dry run - report counts only
 *   php artisan matches:cleanup --force   # apply
 */
class CleanupWeakMatches extends Command
{
    protected $signature = 'matches:cleanup
                            {--force : Apply the changes. Without this the command only reports what it would do}';

    protected $description = 'Delete old weak pending matches and reset lost items stuck on "matched".';

    public const NOTE = 'No suggested matches remain after weak-match cleanup.';

    public function handle(ItemStatusRecorder $status): int
    {
        $apply = (bool) $this->option('force');
        $minimumImage = (float) config('matching.thresholds.minimum_image_score');

        $weakMatches = $this->weakMatches($minimumImage);
        $weakCount = (clone $weakMatches)->count();

        $stuckItems = $this->stuckLostItems($minimumImage)->get();

        $this->line(sprintf('Minimum image score: %.2f', $minimumImage));
        $this->line("Weak pending matches to delete: {$weakCount}");
        $this->line("Lost items to move from matched back to active: {$stuckItems->count()}");

        if (! $apply) {
            $this->warn('Dry run - nothing was changed. Re-run with --force to apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($weakMatches, $stuckItems, $status) {
            $weakMatches->delete();

            foreach ($stuckItems as $lost) {
                $status->transition($lost, LostItemStatus::Active, null, self::NOTE);
            }
        });

        $this->info("Deleted {$weakCount} weak matches and reset {$stuckItems->count()} lost items to active.");

        return self::SUCCESS;
    }

    /** Pending matches the current rules would not create. */
    private function weakMatches(float $minimumImage): Builder
    {
        return ItemMatch::query()
            ->where('status', MatchStatus::PendingReview)
            ->where(fn ($q) => $q->whereNull('text_score')->orWhere('text_score', '<=', 0))
            ->where(fn ($q) => $q->whereNull('image_score')->orWhere('image_score', '<', $minimumImage));
    }

    /**
     * Lost items marked "matched" that have no non-dismissed match left
     * once the weak ones are gone. Evaluated before the delete so the dry
     * run reports the same number the real run would act on.
     */
    private function stuckLostItems(float $minimumImage): Builder
    {
        $weakIds = $this->weakMatches($minimumImage)->select('id');

        return LostItem::query()
            ->where('status', LostItemStatus::Matched)
            ->whereDoesntHave('matches', fn ($q) => $q
                ->where('status', '!=', MatchStatus::Dismissed)
                ->whereNotIn('item_matches.id', $weakIds));
    }
}
