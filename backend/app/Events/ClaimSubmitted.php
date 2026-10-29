<?php

namespace App\Events;

use App\Models\Claim;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A student submitted a claim. Staff-only: students must not learn that
 * someone else is claiming an item they are also interested in.
 */
class ClaimSubmitted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Claim $claim)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('staff')];
    }

    public function broadcastAs(): string
    {
        return 'claim.submitted';
    }

    public function broadcastWith(): array
    {
        return [
            'claimId' => (string) $this->claim->id,
            'foundItemId' => (string) $this->claim->found_item_id,
            // Note: no claimant name. Staff open the claim to see that,
            // which keeps the identity behind an authorised request.
        ];
    }
}
