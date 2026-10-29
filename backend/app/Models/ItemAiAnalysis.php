<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ItemAiAnalysis extends Model
{
    use HasFactory;

    // Laravel would guess "item_ai_analyses"; the table is singular.
    protected $table = 'item_ai_analysis';

    // Morph keys fillable for the same reason as ItemEmbedding: written
    // only by AnalyzeItemImage, and required by updateOrCreate().
    protected $fillable = [
        'item_type',
        'item_id',
        'detected_category',
        'detected_colour',
        'detected_brand',
        'detected_features',
        'generated_description',
        'model_name',
        'model_version',
    ];

    protected function casts(): array
    {
        return ['detected_features' => 'array'];
    }

    public function item(): MorphTo
    {
        return $this->morphTo();
    }
}
