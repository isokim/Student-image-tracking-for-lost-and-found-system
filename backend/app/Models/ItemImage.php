<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class ItemImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'disk',
        'path',
        'thumbnail_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'checksum',
    ];

    /**
     * SECURITY: `path` is a location on a private disk, never a public URL.
     * It is hidden so it cannot leak into a JSON response - clients only
     * ever receive the authorised route below.
     */
    protected $hidden = ['path', 'thumbnail_path', 'disk'];

    public function item(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The only way a client gets at an image. This route runs through the
     * ItemImagePolicy, so a private found-item photo is not readable just
     * because someone guessed the id.
     */
    public function url(): string
    {
        return route('item-images.show', $this);
    }

    public function thumbnailUrl(): ?string
    {
        return $this->thumbnail_path ? route('item-images.thumbnail', $this) : null;
    }

    /** Deletes the file from disk as well as the row. */
    public function deleteFile(): void
    {
        $disk = Storage::disk($this->disk);
        $disk->delete(array_filter([$this->path, $this->thumbnail_path]));
    }
}
