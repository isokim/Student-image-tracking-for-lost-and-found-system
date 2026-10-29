<?php

namespace App\Http\Requests\Items;

use App\Services\ItemImageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a new lost report.
 *
 * Note there is no user_id rule. Ownership comes from the authenticated
 * session in LostItemService, so a payload claiming to be someone else
 * has nothing to attach to.
 */
class StoreLostItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Delegates to LostItemPolicy rather than re-deciding here.
        return $this->user()->can('create', \App\Models\LostItem::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:150'],

            // The frontend sends a slug from its ItemCategory union. It
            // must exist and be active, so a removed category cannot be
            // resurrected by posting its old slug.
            'category' => ['required', 'string', Rule::exists('categories', 'slug')->where('is_active', true)],

            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'colour' => ['nullable', 'string', 'max:50'],

            /**
             * Free text, written by the person filing the report: "near
             * the ATM by the main gate".
             *
             * There is no fixed list to pick from any more - a dropdown
             * can only offer the places somebody thought of in advance,
             * and campus layouts change faster than that.
             */
            'location' => ['required', 'string', 'min:2', 'max:150'],

            /**
             * Cannot be in the future: you have not lost it yet.
             *
             * One day of slack, because the form now sends a bare date
             * and the app runs in UTC: a student in UTC+3 filing at 01:00
             * local is already on "tomorrow" as far as the server is
             * concerned, and rejecting that would be a bug, not a
             * validation. A report dated a week ahead is still refused.
             */
            'lostAt' => [
                'required',
                'date',
                'before_or_equal:'.now()->addDay()->toDateString(),
                'after:'.now()->subYears(2)->toDateString(),
            ],

            'additionalInfo' => ['nullable', 'string', 'max:2000'],

            /**
             * SECURITY: file upload rules.
             *
             * `image` and `mimetypes` both inspect the file's real
             * contents rather than its extension, so shell.php renamed to
             * photo.jpg fails here. ItemImageService repeats these checks
             * because it can also be called from a command or job where
             * no form request ran.
             */
            'photo' => [
                'nullable',
                'file',
                'image',
                'mimetypes:'.implode(',', ItemImageService::ALLOWED_MIME_TYPES),
                'max:'.(ItemImageService::MAX_BYTES / 1024), // in kilobytes
                'dimensions:max_width=10000,max_height=10000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.mimetypes' => 'Only JPEG, PNG and WebP images are accepted.',
            'photo.max' => 'That image is larger than 8MB.',
            'photo.image' => 'That file is not a readable image.',
            'lostAt.before_or_equal' => 'The date you lost it cannot be in the future.',
            'category.exists' => 'Choose a category from the list.',
            'location.required' => 'Tell us where you last had it.',
        ];
    }

    public function attributes(): array
    {
        return [
            'location' => 'location',
            'lostAt' => 'date lost',
            'additionalInfo' => 'additional information',
        ];
    }
}
