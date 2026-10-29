<?php

namespace App\Notifications;

use App\Models\ItemMatch;
use App\Notifications\Concerns\RendersForFrontend;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * "We may have found your item."
 *
 * Note the wording throughout is deliberately tentative - "possible
 * match", "might be". Telling a student their laptop has been found and
 * then having the claim rejected is worse than saying nothing, and the
 * score is a suggestion, not a finding of fact.
 *
 * Delivered on two channels:
 *   database  - the source of truth, survives a closed browser
 *   broadcast - a live nudge to an open tab via Reverb
 *
 * If Reverb is down the student still sees this on next page load,
 * which is the whole reason the database channel is listed first.
 */
class PossibleMatchFound extends Notification implements ShouldQueue
{
    use Queueable, RendersForFrontend;

    public function __construct(public readonly ItemMatch $match)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        $lostTitle = $this->match->lostItem?->title ?? 'your item';

        return $this->payload(
            type: 'possible_match',
            title: 'Possible match found',
            body: "Someone handed in an item that might be {$lostTitle}. Take a look and tell us if it is yours.",
            relatedResourceUrl: "/matches/{$this->match->id}",
        );
    }

    /**
     * Broadcast carries the same payload as the stored notification, so
     * a tab that receives it live can render the item without a refetch.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    /** The event name the React client listens for. */
    public function broadcastType(): string
    {
        return 'notification.created';
    }
}
