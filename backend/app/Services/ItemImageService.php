<?php

namespace App\Services;

use App\Models\ItemImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores uploaded item photos safely.
 *
 * Validation of size and type also happens in the form requests, which
 * is where the user-facing error messages come from. It is repeated here
 * because this service can be called from a job or a command where no
 * form request ran - and an upload path that is only safe when someone
 * remembered to validate first is not safe.
 */
class ItemImageService
{
    /**
     * The only types accepted, checked against the file's actual
     * contents rather than the extension or the browser-supplied
     * Content-Type - both of which the client controls.
     *
     * Matches ACCEPTED_TYPES in the React ImageUploader component.
     */
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /** Mirrors MAX_SIZE_BYTES (8MB) in the React ImageUploader. */
    public const MAX_BYTES = 8 * 1024 * 1024;

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function store(UploadedFile $file, Model $item): ItemImage
    {
        $mimeType = $this->guardAndDetectMimeType($file);

        /**
         * SECURITY: the stored filename is generated here and the client's
         * original name is never used to build a path.
         *
         * A filename like "../../../public/shell.php" or "photo.php.jpg"
         * is therefore harmless: it is recorded as a label only. The
         * extension comes from the detected MIME type, not from what was
         * uploaded.
         */
        $directory = $item->getMorphClass().'/'.$item->getKey();
        $filename = Str::uuid()->toString().'.'.self::EXTENSIONS[$mimeType];
        $path = $directory.'/'.$filename;

        $disk = Storage::disk('items');

        // Re-encoded to strip EXIF - see sanitise().
        $bytes = $this->sanitise($file->getRealPath(), $mimeType);

        if (! $disk->put($path, $bytes)) {
            throw new RuntimeException('The image could not be saved.');
        }

        return $item->images()->create([
            'disk' => 'items',
            'path' => $path,
            // Kept for display only, and truncated so a pathological name
            // cannot overflow the column.
            'original_name' => Str::limit($file->getClientOriginalName() ?: 'upload', 200, ''),
            'mime_type' => $mimeType,
            /**
             * Measured on the bytes actually written, not the upload.
             * Re-encoding changes both, so recording the original values
             * would make size_bytes disagree with the file on disk and
             * make the checksum useless for spotting a duplicate photo.
             */
            'size_bytes' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
        ]);
    }

    /**
     * Re-encodes the image, discarding everything that is not pixels.
     *
     * Two reasons, and both matter here:
     *
     * 1. PRIVACY. Phone cameras write EXIF, and that routinely includes
     *    GPS coordinates, the device serial and a timestamp. Since any
     *    signed-in user can view a lost report's photo, storing the
     *    original bytes would let anyone download a picture and read
     *    where the owner was standing when they took it. For a student
     *    lost-and-found board that is a real deanonymisation risk.
     *
     * 2. SAFETY. Re-encoding destroys anything hidden in the file that
     *    is not image data - polyglot files that are simultaneously a
     *    valid JPEG and a valid script, or payloads tucked into a
     *    metadata comment field.
     *
     * If GD is unavailable the original bytes are kept rather than
     * failing the upload: losing the photo would be worse than keeping
     * the metadata, and the type checks have already run.
     */
    private function sanitise(string $path, string $mimeType): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return file_get_contents($path);
        }

        $image = @imagecreatefromstring(file_get_contents($path));

        if ($image === false) {
            return file_get_contents($path);
        }

        // Preserve transparency for the formats that support it; without
        // this a PNG with an alpha channel comes back with a black one.
        if ($mimeType === 'image/png' || $mimeType === 'image/webp') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        ob_start();

        match ($mimeType) {
            'image/png' => imagepng($image, null, 6),
            'image/webp' => imagewebp($image, null, 90),
            default => imagejpeg($image, null, 88),
        };

        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes !== false && $bytes !== '' ? $bytes : file_get_contents($path);
    }

    public function delete(ItemImage $image): void
    {
        $image->deleteFile();
        $image->delete();
    }

    /**
     * Establishes what the file really is.
     *
     * getMimeType() inspects the file's magic bytes via finfo, so it
     * reports what the file actually contains, not what the upload
     * claimed. getimagesize() is then a second, independent check that
     * the bytes really decode as an image - a PHP script renamed to
     * .jpg fails both.
     */
    private function guardAndDetectMimeType(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw new RuntimeException('The upload did not complete.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('That image is larger than 8MB.');
        }

        $mimeType = $file->getMimeType();

        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException('Only JPEG, PNG and WebP images are accepted.');
        }

        $dimensions = @getimagesize($file->getRealPath());

        if ($dimensions === false) {
            throw new RuntimeException('That file is not a readable image.');
        }

        return $mimeType;
    }
}
