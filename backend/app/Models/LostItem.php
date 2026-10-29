<?php

namespace App\Models;

use App\Enums\LostItemStatus;
use App\Models\Concerns\HasItemRelations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LostItem extends Model
{
    use HasFactory, HasItemRelations, SoftDeletes;

    /**
     * `user_id` and `status` are not fillable: ownership is set by the
     * service from the authenticated user, and status only ever changes
     * through the workflow methods so the transition gets recorded.
     */
    protected $fillable = [
        'category_id',
        'location_text',
        'title',
        'description',
        'colour',
        'additional_info',
        'lost_at',
    ];

    /**
     * Model-level defaults matching the database defaults. Without these,
     * a freshly created model has status=null in memory until it is
     * refreshed, because Eloquent does not read database defaults back.
     */
    protected $attributes = [
        'status' => LostItemStatus::Active->value,
    ];

    protected function casts(): array
    {
        return [
            'lost_at' => 'datetime',
            'ai_processed_at' => 'datetime',
            'status' => LostItemStatus::class,
        ];
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    /** The student who lost the item. Policies compare against this. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
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

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [LostItemStatus::Active, LostItemStatus::Matched]);
    }

    /** Eager-loads everything the API resource needs, avoiding N+1 queries. */
    public function scopeWithApiRelations(Builder $query): Builder
    {
        return $query->with(['user:id,name', 'category:id,slug,name', 'primaryImage']);
    }
}
