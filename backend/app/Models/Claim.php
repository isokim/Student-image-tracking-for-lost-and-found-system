<?php

namespace App\Models;

use App\Enums\ClaimStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Claim extends Model
{
    use HasFactory;

    /**
     * SECURITY - mass assignment protection.
     *
     * status, claimant_id and every reviewer field are omitted on purpose.
     * A claimant must not be able to post status=approved. Those
     * transitions happen only inside ClaimService, which also writes the
     * audit record and fires the notification.
     */
    protected $fillable = [
        'found_item_id',
        'lost_item_id',
        'item_match_id',
        'identifying_information',
    ];

    /** See the note in LostItem: mirrors the database default in memory. */
    protected $attributes = [
        'status' => ClaimStatus::Draft->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => ClaimStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    public function claimant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimant_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function foundItem(): BelongsTo
    {
        return $this->belongsTo(FoundItem::class);
    }

    public function lostItem(): BelongsTo
    {
        return $this->belongsTo(LostItem::class);
    }

    public function itemMatch(): BelongsTo
    {
        return $this->belongsTo(ItemMatch::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(ClaimVerification::class);
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('claimant_id', $user->id);
    }

    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('status', ClaimStatus::PendingReview);
    }

    public function scopeWithApiRelations(Builder $query): Builder
    {
        return $query->with([
            'claimant:id,name',
            'reviewer:id,name',
            'foundItem' => fn ($q) => $q->withApiRelations(),
            'lostItem' => fn ($q) => $q->withApiRelations(),
            'verifications',
        ]);
    }
}
