<?php

namespace App\Models;

use App\Enums\VerificationResult;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimVerification extends Model
{
    use HasFactory;

    protected $fillable = ['question', 'answer', 'expected_answer', 'result'];

    protected function casts(): array
    {
        return ['result' => VerificationResult::class];
    }

    /**
     * SECURITY: the correct answer must never reach the claimant. Anyone
     * who knew it could claim anything. Hidden here as a backstop; the API
     * resource includes it only for staff and admins.
     */
    protected $hidden = ['expected_answer'];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }
}
