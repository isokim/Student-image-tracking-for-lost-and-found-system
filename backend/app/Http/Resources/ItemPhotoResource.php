<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the ItemPhoto interface in the React app.
 *
 * Note what is absent: the storage path and the disk name. The client
 * only ever gets a route URL, and that route runs ItemImagePolicy before
 * streaming any bytes.
 *
 * @mixin \App\Models\ItemImage
 */
class ItemPhotoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'url' => $this->url(),
            'thumbnailUrl' => $this->thumbnailUrl(),
        ];
    }
}
