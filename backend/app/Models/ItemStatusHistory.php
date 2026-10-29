<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ItemStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'item_status_history';

    protected $fillable = ['from_status', 'to_status', 'changed_by', 'note'];

    public function item(): MorphTo
    {
        return $this->morphTo();
    }

    /** Null when the system, not a person, made the change. */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
