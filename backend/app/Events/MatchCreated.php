<?php

namespace App\Events;

use App\Models\ItemMatch;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new possible match was found for someone's lost report.
 *
 * SECURITY: broadcast on a PRIVATE channel keyed to the student who
 * filed the lost report, and the payload carries ids and titles only -
 * no descriptions, no private verification detail, no personal data
 * about the finder. Anything a listener needs beyond this is fetched
 * through the API, where policies apply.
 */
class MatchCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly ItemMatch $match)
    {
    }

    public function broadcastOn(): array
    {
        // routes/channels.php proves the subscriber really is this user.
        return [new PrivateChannel('users.'.$this->match->lostItem->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'match.created';
    }

    public function broadcastWith(): array
    {
        return [
            'matchId' => (string) $this->match->id,
            'lostItemId' => (string) $this->match->lost_item_id,
            'confidence' => $this->match->confidence->value,
            // A hint for the toast, not the item's full description.
            'title' => $this->match->foundItem?->title,
        ];
    }
}
