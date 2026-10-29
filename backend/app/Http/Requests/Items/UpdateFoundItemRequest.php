<?php

namespace App\Http\Requests\Items;

use Illuminate\Validation\Rule;

class UpdateFoundItemRequest extends StoreFoundItemRequest
{
    public function authorize(): bool
    {
        return true; // the controller authorises against the specific item
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'min:3', 'max:150'],
            'category' => ['sometimes', 'string', Rule::exists('categories', 'slug')->where('is_active', true)],
            'description' => ['sometimes', 'string', 'min:10', 'max:2000'],
            'privateVerificationInfo' => ['nullable', 'string', 'max:2000'],
            'colour' => ['nullable', 'string', 'max:50'],
            'location' => ['sometimes', 'string', 'min:2', 'max:150'],
            'foundAt' => ['sometimes', 'date', 'before_or_equal:'.now()->addDay()->toDateString()],
            'additionalInfo' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
