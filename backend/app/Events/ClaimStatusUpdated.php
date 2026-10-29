<?php

namespace App\Events;

use App\Models\Claim;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A claim was approved, rejected or completed.
 *
 * Goes to two private channels: the claimant's own channel, and the
 * staff channel so the review queue updates live for whoever is on the
 * desk. Nothing about the claimant's verification answers is included.
 */
class ClaimStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Claim $claim)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('users.'.$this->claim->claimant_id),
            new PrivateChannel('staff'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'claim.status-updated';
    }

    public function broadcastWith(): array
    {
        return [
            'claimId' => (string) $this->claim->id,
            'status' => $this->claim->status->value,
        ];
    }
}
