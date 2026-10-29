<?php

namespace App\Models;

use App\Enums\FoundItemStatus;
use App\Models\Concerns\HasItemRelations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FoundItem extends Model
{
    use HasFactory, HasItemRelations, SoftDeletes;

    protected $fillable = [
        'category_id',
        'location_text',
        'title',
        'description',
        'private_verification_info',
        'colour',
        'additional_info',
        'found_at',
    ];

    /** See the note in LostItem: mirrors the database default in memory. */
    protected $attributes = [
        'status' => FoundItemStatus::PendingVerification->value,
    ];

    protected function casts(): array
    {
        return [
            'found_at' => 'datetime',
            'verified_at' => 'datetime',
            'ai_processed_at' => 'datetime',
            'status' => FoundItemStatus::class,
        ];
    }

    /**
     * SECURITY - defence in depth.
     *
     * The API resource decides who sees the private verification detail,
     * but hiding it here too means an accidental `return $foundItem;` or
     * `->toArray()` anywhere in the codebase still cannot leak it.
     * Code that legitimately needs it reads the attribute directly.
     */
    protected $hidden = ['private_verification_info'];

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    /** Whoever handed the item in. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(ItemMatch::class)->orderByDesc('overall_score');
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * What a student is allowed to see in listings and search. Pending
     * reports are visible so matching can happen immediately; rejected
     * reports stay hidden.
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn('status', [
            FoundItemStatus::PendingVerification,
            FoundItemStatus::Verified,
            FoundItemStatus::Claimed,
            FoundItemStatus::Returned,
        ]);
    }

    public function scopeAwaitingVerification(Builder $query): Builder
    {
        return $query->where('status', FoundItemStatus::PendingVerification);
    }

    public function scopeWithApiRelations(Builder $query): Builder
    {
        return $query->with([
            'user:id,name,role',
            'verifier:id,name',
            'category:id,slug,name',
            'primaryImage',
        ]);
    }
}
