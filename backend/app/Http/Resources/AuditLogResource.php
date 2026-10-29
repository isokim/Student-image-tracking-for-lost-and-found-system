<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the AuditLogEntry interface in
 * src/features/admin/adminService.ts.
 *
 * @mixin \App\Models\AuditLog
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,

            // Null for events with no signed-in user, such as a failed
            // login attempt. The UI renders that as "System".
            'actor' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => (string) $this->user->id,
                'name' => $this->user->name,
                'role' => $this->user->role->value,
            ] : null),

            'action' => $this->action,

            // The short morph alias, e.g. "found_item".
            'targetType' => $this->entity_type,
            'targetId' => $this->entity_id !== null ? (string) $this->entity_id : null,

            'description' => $this->description,
            'ipAddress' => $this->ip_address,
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
