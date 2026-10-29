<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ItemImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves item photos.
 *
 * Why photos go through a controller at all, rather than being written
 * to storage/app/public and symlinked into the web root:
 *
 * A public symlink makes every uploaded file readable by anyone who
 * knows or guesses the URL, with no authentication whatsoever. These
 * photos include handed-in student IDs, passports and phone lock
 * screens. Routing them through here means ItemImagePolicy runs before
 * a single byte is sent, and the policy delegates to the parent item's
 * own view rules - so a photo can never be more visible than the item
 * it belongs to.
 */
class ItemImageController extends Controller
{
    public function show(Request $request, ItemImage $itemImage): StreamedResponse
    {
        // Denies as 404 for anyone not entitled to the parent item, so
        // image ids cannot be probed.
        $this->authorize('view', $itemImage);

        return $this->stream($itemImage, $itemImage->path);
    }

    public function thumbnail(Request $request, ItemImage $itemImage): StreamedResponse
    {
        $this->authorize('view', $itemImage);

        // Falls back to the full image when no thumbnail was generated,
        // so the UI never shows a broken image.
        return $this->stream($itemImage, $itemImage->thumbnail_path ?? $itemImage->path);
    }

    private function stream(ItemImage $image, string $path): StreamedResponse
    {
        $disk = Storage::disk($image->disk);

        abort_unless($disk->exists($path), 404, 'That image could not be found.');

        return $disk->response($path, null, [
            /**
             * The stored MIME type, which ItemImageService established by
             * inspecting the file's actual bytes. Never a client-supplied
             * value.
             */
            'Content-Type' => $image->mime_type,

            /**
             * SECURITY: stops a browser second-guessing the content type
             * and executing something it decided looks like HTML or
             * JavaScript. Combined with the type whitelist on upload,
             * this is what makes serving user files safe.
             */
            'X-Content-Type-Options' => 'nosniff',

            // inline, with a server-generated filename. The client's
            // original filename never reaches a header.
            'Content-Disposition' => 'inline; filename="'.$image->id.'"',

            /**
             * private: these are authorised responses, so a shared proxy
             * must not cache one user's photo and hand it to another.
             */
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
