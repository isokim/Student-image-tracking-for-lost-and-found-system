<?php

namespace App\Http\Requests\Items;

use App\Services\ItemImageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFoundItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\FoundItem::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'category' => ['required', 'string', Rule::exists('categories', 'slug')->where('is_active', true)],

            // The PUBLIC description. Whoever writes this should assume
            // everyone will read it.
            'description' => ['required', 'string', 'min:10', 'max:2000'],

            /**
             * The detail only the true owner would know. Required:
             * without it, staff reviewing a claim have nothing to check
             * the claimant's "details that prove it's yours" against.
             *
             * Never returned to anyone but the finder and staff; see
             * FoundItemResource.
             */
            'privateVerificationInfo' => ['required', 'string', 'min:10', 'max:2000'],

            'colour' => ['nullable', 'string', 'max:50'],

        
            'location' => ['required', 'string', 'min:2', 'max:150'],
           
            'foundAt' => [
                'required',
                'date',
                'before_or_equal:'.now()->addDay()->toDateString(),
                'after:'.now()->subYears(2)->toDateString(),
            ],
            'additionalInfo' => ['nullable', 'string', 'max:2000'],

            'photo' => [
                'nullable',
                'file',
                'image',
                'mimetypes:'.implode(',', ItemImageService::ALLOWED_MIME_TYPES),
                'max:'.(ItemImageService::MAX_BYTES / 1024),
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
            'foundAt.before_or_equal' => 'The date you found it cannot be in the future.',
            'location.required' => 'Tell us where you found it.',
            'privateVerificationInfo.required' => 'Add a hidden detail only the owner would know, so staff can check claims.',
            'privateVerificationInfo.min' => 'Give a bit more detail (at least 10 characters) so staff can check claims.',
        ];
    }




    public function attributes(): array
    {
        return [
            'location' => 'location',
            'foundAt' => 'date found',
            'privateVerificationInfo' => 'hidden details',
            'additionalInfo' => 'additional information',
        ];
    }
}
