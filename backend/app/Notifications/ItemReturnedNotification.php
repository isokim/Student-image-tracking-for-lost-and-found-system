<?php

namespace App\Notifications;

use App\Models\Claim;
use App\Notifications\Concerns\RendersForFrontend;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Closes the loop. Also serves as the student's record that the handover
 * happened, which matters if the collection is ever disputed.
 */
class ItemReturnedNotification extends Notification implements ShouldQueue
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
            type: 'item_returned',
            title: 'Item returned',
            body: "\"{$title}\" has been marked as returned to you. If that is not right, contact the Lost & Found office.",
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
