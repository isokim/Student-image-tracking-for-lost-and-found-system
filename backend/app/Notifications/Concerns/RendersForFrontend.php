<?php

namespace App\Notifications\Concerns;

/**
 * Shared shape for every notification stored in the database.
 *
 * The React app reads {type, title, body, relatedResourceUrl} off the
 * notification's data blob (see NotificationResource). Putting the
 * contract in one trait means a new notification type cannot quietly
 * omit a key the UI depends on.
 *
 * `type` is the slug from the frontend's NotificationType union, not the
 * PHP class name - the UI switches on it to choose an icon.
 */
trait RendersForFrontend
{
    protected function payload(
        string $type,
        string $title,
        string $body,
        ?string $relatedResourceUrl = null,
    ): array {
        return [
            'type' => $type,
            'title' => $title,
            'body' => $body,
            // A frontend route, e.g. "/claims/12". Not an API URL: this
            // is what the browser navigates to when the notification is
            // clicked.
            'relatedResourceUrl' => $relatedResourceUrl,
        ];
    }
}
