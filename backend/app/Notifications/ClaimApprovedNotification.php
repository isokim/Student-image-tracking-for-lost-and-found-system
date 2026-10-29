<?php

namespace App\Notifications;

use App\Models\Claim;
use App\Notifications\Concerns\RendersForFrontend;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class ClaimApprovedNotification extends Notification implements ShouldQueue
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
        $title = $this->claim->foundItem?->title ?? 'your item';

        return $this->payload(
            type: 'claim_approved',
            title: 'Claim approved',
            body: "Your claim for \"{$title}\" was approved. Bring your student ID to the Lost & Found office to collect it.",
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
