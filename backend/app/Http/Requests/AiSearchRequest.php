<?php

namespace App\Http\Requests;

use App\Services\ItemImageService;
use Illuminate\Validation\Rule;

/**
 * Image search: "here is a photo of what I lost".
 *
 * Extends SearchRequest so the same filters can narrow an image search,
 * and adds the upload rules. The image is embedded and discarded - it is
 * never stored as an item, and the AI service is told not to add it to
 * the persistent index.
 */
class AiSearchRequest extends SearchRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'image' => [
                'required',
                'file',
                'image',
                'mimetypes:'.implode(',', ItemImageService::ALLOWED_MIME_TYPES),
                'max:'.(ItemImageService::MAX_BYTES / 1024),
                /**
                 * The same guard the item-upload requests carry. Without
                 * it a ~1MB decompression bomb (a 40000x40000 PNG) passes
                 * every other check and is then decoded by the vision
                 * model. The route's throttle limits how often that can
                 * be done, not how expensive one request is.
                 */
                'dimensions:max_width=10000,max_height=10000',
            ],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
    }

    public function messages(): array
    {
        return [
            'image.required' => 'Choose a photo to search with.',
            'image.mimetypes' => 'Only JPEG, PNG and WebP images are accepted.',
            'image.max' => 'That image is larger than 8MB.',
        ];
    }
}
