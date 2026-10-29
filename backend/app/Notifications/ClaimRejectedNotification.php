<?php

namespace App\Notifications;

use App\Models\Claim;
use App\Notifications\Concerns\RendersForFrontend;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Includes the reviewer's notes so the student knows why. A bare
 * "rejected" with no reason invites them to simply file the claim again.
 */
class ClaimRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable, RendersForFrontend;

    public function __construct(public readonly Claim $claim)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        $title = $this->claim->foundItem?->title ?? 'the item';
        $reason = $this->claim->review_notes
            ? " Reason: {$this->claim->review_notes}"
            : '';

        return $this->payload(
            type: 'claim_rejected',
            title: 'Claim not approved',
            body: "Your claim for \"{$title}\" was not approved.{$reason}",
            relatedResourceUrl: "/claims/{$this->claim->id}",
        );
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function broadcastType(): string
    {
        return 'notification.created';
    }
}
