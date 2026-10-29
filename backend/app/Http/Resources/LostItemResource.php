<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Matches the LostItemReport interface in src/types/index.ts.
 *
 * This is the second half of the authorisation story. The policy decides
 * whether you may open the record at all; this decides which fields you
 * get once you have. A student browsing the board sees the report; only
 * the owner and staff see the private notes on it.
 *
 * @mixin \App\Models\LostItem
 */
class LostItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        $canSeePrivate = $viewer !== null
            && Gate::forUser($viewer)->allows('viewPrivateDetails', $this->resource);

        return [
            'id' => (string) $this->id,
            'title' => $this->title,

            // The frontend's ItemCategory union is a slug, not an object.
            'category' => $this->whenLoaded('category', fn () => $this->category->slug),

            'description' => $this->description,
            'colour' => $this->colour,

            // The place in the reporter's own words - the only location
            // there is now that the admin-managed list is gone.
            'locationText' => $this->location_text,
            'lostAt' => $this->lost_at?->toIso8601String(),

            'photo' => $this->whenLoaded(
                'primaryImage',
                fn () => $this->primaryImage ? new ItemPhotoResource($this->primaryImage) : null
            ),

            /**
             * SECURITY: the owner's extra identifying notes. Omitted
             * entirely - not nulled - for anyone else, so the response
             * does not even hint that there is something to see.
             */
            'additionalInfo' => $this->when($canSeePrivate, fn () => $this->additional_info),

            'status' => $this->status->value,

            // Just id and name. The reporter's email, admission number and
            // role are nobody else's business on a public board.
            'reportedBy' => $this->whenLoaded('user', fn () => [
                'id' => (string) $this->user->id,
                'name' => $this->user->name,
            ]),

            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
