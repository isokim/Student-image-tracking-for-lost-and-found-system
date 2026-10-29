<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Category */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            // The slug is what item payloads use, so the admin screen
            // needs to see it alongside the display name.
            'slug' => $this->slug,
            'name' => $this->name,
            'isActive' => $this->is_active,
        ];
    }
}
