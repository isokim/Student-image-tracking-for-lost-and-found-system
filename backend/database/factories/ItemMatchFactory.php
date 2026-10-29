<?php

namespace Database\Factories;

use App\Enums\MatchConfidence;
use App\Enums\MatchStatus;
use App\Models\FoundItem;
use App\Models\ItemMatch;
use App\Models\LostItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ItemMatch> */
class ItemMatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lost_item_id' => LostItem::factory(),
            'found_item_id' => FoundItem::factory()->verified(),
            'image_score' => 0.80,
            'text_score' => 0.60,
            'category_score' => 1.00,
            'colour_score' => 1.00,
            'location_score' => 1.00,
            'time_score' => 0.90,
            'overall_score' => 0.83,
            'confidence' => MatchConfidence::Possible,
            'status' => MatchStatus::PendingReview,
            'scoring_version' => 'v1',
        ];
    }
}
