<?php

namespace App\Notifications;

use App\Models\FoundItem;
use App\Notifications\Concerns\RendersForFrontend;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/** Tells whoever handed an item in that staff have accepted it. */
class FoundItemVerifiedNotification extends Notification implements ShouldQueue
{
    use Queueable, RendersForFrontend;

    public function __construct(public readonly FoundItem $item)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload(
            type: 'found_item_verified',
            title: 'Found item verified',
            body: "Thanks - \"{$this->item->title}\" has been verified and is now listed so its owner can find it.",
            relatedResourceUrl: "/found-items/{$this->item->id}",
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
