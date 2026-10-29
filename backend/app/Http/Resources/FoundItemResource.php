<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Matches the FoundItemReport interface in src/types/index.ts.
 *
 * The single most important line in this file is the one guarding
 * privateVerificationInfo. That field is the evidence a claim is checked
 * against - if a browsing student could read it, they could recite it
 * back and claim an item that is not theirs. The React type even
 * comments it as "never shown publicly"; this is where that is enforced.
 *
 * @mixin \App\Models\FoundItem
 */
class FoundItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        $canSeePrivate = $viewer !== null
            && Gate::forUser($viewer)->allows('viewPrivateDetails', $this->resource);

        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'category' => $this->whenLoaded('category', fn () => $this->category->slug),

            // Safe for listings and search.
            'description' => $this->description,

            /**
             * SECURITY: finder and staff only. Omitted for everyone else,
             * including the person claiming the item.
             */
            'privateVerificationInfo' => $this->when(
                $canSeePrivate,
                fn () => $this->private_verification_info
            ),

            'colour' => $this->colour,

            // See the note in LostItemResource - typed text is the only
            // location now.
            'locationText' => $this->location_text,
            'foundAt' => $this->found_at?->toIso8601String(),

            'photo' => $this->whenLoaded(
                'primaryImage',
                fn () => $this->primaryImage ? new ItemPhotoResource($this->primaryImage) : null
            ),

            'additionalInfo' => $this->when($canSeePrivate, fn () => $this->additional_info),

            'status' => $this->status->value,

            // role is included here (unlike on lost items) because the UI
            // distinguishes an item handed in by staff from one handed in
            // by a student.
            'reportedBy' => $this->whenLoaded('user', fn () => [
                'id' => (string) $this->user->id,
                'name' => $this->user->name,
                'role' => $this->user->role->value,
            ]),

            'verifiedBy' => $this->whenLoaded('verifier', fn () => $this->verifier ? [
                'id' => (string) $this->verifier->id,
                'name' => $this->verifier->name,
            ] : null),

            // Why staff turned an item away. Shown to the finder so they
            // know what happened to their report.
            'rejectionReason' => $this->when(
                $canSeePrivate && $this->rejection_reason !== null,
                fn () => $this->rejection_reason
            ),

            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
