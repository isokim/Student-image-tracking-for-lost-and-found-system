<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. Nothing in the application updates or deletes these rows,
 * and only admins can read them.
 */
class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'description',
        'ip_address',
        'user_agent',
    ];

    /** Null for events with no authenticated user, such as a failed login. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
