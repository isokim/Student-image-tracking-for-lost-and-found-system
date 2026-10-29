<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Records every status transition an item goes through.
 *
 * Status changes go through here rather than being assigned directly, so
 * the timeline on the item detail page is complete by construction. If a
 * controller sets $item->status itself, the history silently develops a
 * hole - which is exactly the kind of gap that matters when two students
 * both claim the same laptop.
 */
class ItemStatusRecorder
{
    /**
     * @param  Model  $item  a LostItem or FoundItem
     * @param  \BackedEnum  $to  the new status
     * @param  User|null  $changedBy  null when the system made the change,
     *                                e.g. a queued job marking an item
     *                                "matched"
     */
    public function transition(
        Model $item,
        \BackedEnum $to,
        ?User $changedBy = null,
        ?string $note = null,
    ): void {
        $from = $item->status?->value;

        // Nothing to record, and no reason to touch the row.
        if ($from === $to->value) {
            return;
        }

        $item->status = $to;
        $item->save();

        $item->statusHistory()->create([
            'from_status' => $from,
            'to_status' => $to->value,
            'changed_by' => $changedBy?->id,
            'note' => $note,
        ]);
    }
}
