<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Claim-scoped message between a claimant and reviewing staff. The table
 * and model exist because the brief asks for them; no routes are exposed
 * yet, because the React app has no messaging screen.
 */
class Message extends Model
{
    use HasFactory;

    protected $fillable = ['claim_id', 'sender_id', 'recipient_id', 'body'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
