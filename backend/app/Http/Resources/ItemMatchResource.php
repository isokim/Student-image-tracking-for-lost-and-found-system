<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the ItemMatch interface in src/types/index.ts.
 *
 * The database stores seven independent 0..1 component scores; the React
 * app wants a smaller, friendlier breakdown (percentages and booleans).
 * Translating here rather than storing the UI's shape means the weights
 * and components can change without a migration.
 *
 * @mixin \App\Models\ItemMatch
 */
class ItemMatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'lostItem' => new LostItemResource($this->whenLoaded('lostItem')),
            'foundItem' => new FoundItemResource($this->whenLoaded('foundItem')),

            // "possible" or "likely" - never a claim of certainty.
            'overallConfidence' => $this->confidence->value,

            'scores' => [
                // 0..1 in the database, 0..100 for display. Null when
                // neither item had a photo to compare.
                'visualSimilarity' => $this->image_score !== null
                    ? (int) round($this->image_score * 100)
                    : null,

                'descriptionSimilarity' => (int) round($this->text_score * 100),

                // Booleans, because a half-matching category is not a
                // concept the UI has room to explain.
                'categoryMatch' => $this->category_score >= 1.0,
                'colourMatch' => $this->colour_score >= 0.75,

                // Derived from the location score rather than stored
                // separately: 1.0 is the same place, 0.6 the same campus
                // area, anything lower is elsewhere.
                'locationProximity' => match (true) {
                    $this->location_score >= 1.0 => 'same',
                    $this->location_score >= 0.6 => 'close',
                    default => 'different',
                },
            ],

            'status' => $this->status->value,
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
