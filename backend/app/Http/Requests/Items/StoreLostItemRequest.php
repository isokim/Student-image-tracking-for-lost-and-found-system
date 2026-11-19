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

            'category' => ['required', 'string', Rule::exists('categories', 'slug')->where('is_active', true)],

            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'colour' => ['nullable', 'string', 'max:50'],

           
            'location' => ['required', 'string', 'min:2', 'max:150'],

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
