<?php

namespace App\Events;

use App\Models\FoundItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Staff verified a found item.
 *
 * Sent to the finder's private channel and the staff channel. It is NOT
 * broadcast campus-wide even though the item is now publicly listed -
 * a live feed of everything handed in would be a gift to anyone looking
 * to claim items that are not theirs.
 */
class FoundItemVerified implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly FoundItem $item)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('users.'.$this->item->user_id),
            new PrivateChannel('staff'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'found-item.verified';
    }

    public function broadcastWith(): array
    {
        return [
            'foundItemId' => (string) $this->item->id,
            'title' => $this->item->title,
            'status' => $this->item->status->value,
        ];
    }
}
