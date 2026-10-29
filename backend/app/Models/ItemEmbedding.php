<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A pointer to a vector that lives in FAISS, not the vector itself.
 * Laravel never does similarity maths - it asks the AI service, which
 * owns the index. That separation is what lets the AI service move to
 * the cloud without touching this database.
 */
class ItemEmbedding extends Model
{
    use HasFactory;

    /**
     * The morph keys are fillable because this row is only ever written
     * by GenerateItemEmbedding, never from a request - and
     * updateOrCreate() needs them fillable or it strips the very columns
     * it is matching on, producing an INSERT with a null item_type.
     */
    protected $fillable = [
        'item_type',
        'item_id',
        'embedding_reference',
        'model_name',
        'model_version',
        'dimensions',
    ];

    public function item(): MorphTo
    {
        return $this->morphTo();
    }
}
