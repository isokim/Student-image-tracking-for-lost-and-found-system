<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a User for the API.
 *
 * Two conventions apply to every resource in this folder, both driven by
 * the existing React client (src/types/index.ts):
 *
 *   1. Keys are camelCase, not Laravel's snake_case.
 *   2. Ids are strings, because the TypeScript types declare `id: string`.
 *      JSON numbers would still work at runtime but would not type-check.
 *
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,

            // Students have an admission number, staff/admin a department.
            'admissionNumber' => $this->admission_number,
            'department' => $this->whenLoaded('department', fn () => $this->department?->name),

            /**
             * The column exists for later, but there is no avatar upload
             * endpoint yet because the React app has no upload control -
             * its Avatar component falls back to the user's initials when
             * this is null.
             */
            'avatarUrl' => null,
            'emailVerifiedAt' => $this->email_verified_at?->toIso8601String(),
            'createdAt' => $this->created_at->toIso8601String(),

            /**
             * Account status is exposed only to admins. A student has no
             * reason to learn whether another account is suspended.
             */
            'status' => $this->when(
                $request->user()?->isAdmin() ?? false,
                fn () => $this->status->value
            ),
        ];
    }
}
