<?php

namespace App\Models;

use App\Enums\MatchConfidence;
use App\Enums\MatchStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A suggested pairing between one lost report and one found item.
 * Every component score is kept so the UI can explain the suggestion
 * and so weights can be retuned without recomputing the parts.
 */
class ItemMatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'lost_item_id',
        'found_item_id',
        'image_score',
        'text_score',
        'category_score',
        'colour_score',
        'location_score',
        'time_score',
        'overall_score',
        'confidence',
        'scoring_version',
    ];

    /** See the note in LostItem: mirrors the database defaults in memory. */
    protected $attributes = [
        'status' => MatchStatus::PendingReview->value,
        'confidence' => MatchConfidence::Possible->value,
        'scoring_version' => 'v1',
    ];

    protected function casts(): array
    {
        return [
            'image_score' => 'float',
            'text_score' => 'float',
            'category_score' => 'float',
            'colour_score' => 'float',
            'location_score' => 'float',
            'time_score' => 'float',
            'overall_score' => 'float',
            'confidence' => MatchConfidence::class,
            'status' => MatchStatus::class,
            'dismissed_at' => 'datetime',
        ];
    }

    public function lostItem(): BelongsTo
    {
        return $this->belongsTo(LostItem::class);
    }

    public function foundItem(): BelongsTo
    {
        return $this->belongsTo(FoundItem::class);
    }

    /**
     * A match is private to the student who filed the lost report. This is
     * the check MatchPolicy uses to stop one student browsing the matches
     * belonging to another student.
     */
    public function belongsToStudent(User $user): bool
    {
        return $this->lostItem?->user_id === $user->id;
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', '!=', MatchStatus::Dismissed);
    }

    public function scopeWithApiRelations(Builder $query): Builder
    {
        return $query->with([
            'lostItem' => fn ($q) => $q->withApiRelations(),
            'foundItem' => fn ($q) => $q->withApiRelations(),
        ]);
    }
}
