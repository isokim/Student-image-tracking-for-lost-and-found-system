<?php

namespace App\Jobs;

use App\Models\FoundItem;
use App\Models\LostItem;
use App\Services\Matching\MatchFinderService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scores an item against the other side of the board and records any
 * matches worth showing.
 *
 * Queued for two reasons: it can involve a round trip to the vector
 * index, and it sends notifications - neither of which a student should
 * wait on while their report saves.
 *
 * Takes a type and an id rather than a model instance so the job
 * survives the item being edited between queueing and running: it always
 * scores the current state, not a snapshot serialised minutes ago.
 */
class FindPotentialMatches implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 180;
    public array $backoff = [15, 60];

    /**
     * How long the uniqueness lock is held. Without a cap, a job that
     * dies without releasing its lock would block this item from ever
     * being matched again.
     */
    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $itemType,
        public readonly int $itemId,
    ) {
    }

    public function handle(MatchFinderService $finder): void
    {
        match ($this->itemType) {
            'lost_item' => $this->forLost($finder),
            'found_item' => $this->forFound($finder),
            default => null,
        };
    }

    private function forLost(MatchFinderService $finder): void
    {
        $item = LostItem::with(['category', 'user', 'embedding'])->find($this->itemId);

        if ($item !== null) {
            $finder->findForLostItem($item);
        }
    }

    private function forFound(MatchFinderService $finder): void
    {
        $item = FoundItem::with(['category', 'user', 'embedding'])->find($this->itemId);

        if ($item !== null) {
            $finder->findForFoundItem($item);
        }
    }

    /**
     * Stops the same item being queued for matching several times over.
     * Without this, editing a report three times in a minute would run
     * the whole scoring pass three times.
     */
    public function uniqueId(): string
    {
        return $this->itemType.':'.$this->itemId;
    }
}
