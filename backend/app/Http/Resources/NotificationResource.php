<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the AppNotification interface in src/types/index.ts.
 *
 * Laravel stores notification payloads as a JSON `data` blob keyed by
 * notification class. The React app wants a flat {type,title,body}
 * shape, so each notification writes those three keys into data and
 * this resource lifts them out. Keeping the translation here means
 * adding a notification type does not require a frontend change.
 *
 * @mixin \Illuminate\Notifications\DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->data ?? [];

        return [
            'id' => (string) $this->id,

            // The short slug ("possible_match"), not the PHP class name -
            // the frontend switches on this to pick an icon.
            'type' => $data['type'] ?? 'staff_message',

            'title' => $data['title'] ?? 'Notification',
            'body' => $data['body'] ?? '',

            // Null means unread; the UI relies on that.
            'readAt' => $this->read_at?->toIso8601String(),

            // A frontend route such as "/claims/12", so clicking the
            // notification lands on the right screen.
            'relatedResourceUrl' => $data['relatedResourceUrl'] ?? null,

            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
