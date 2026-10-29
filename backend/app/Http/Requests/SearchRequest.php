<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Keyword and filter search.
 *
 * Every filter is optional; an empty request is a valid "show me
 * everything I'm allowed to see". Lengths are capped so a huge search
 * term cannot be used to hammer the database.
 */
class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authentication is enforced by the route middleware
    }

    public function rules(): array
    {
        return [
            'query' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'string', Rule::exists('categories', 'slug')],
            'colour' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:150'],
            'dateFrom' => ['nullable', 'date'],
            'dateTo' => ['nullable', 'date', 'after_or_equal:dateFrom'],

            // Which board to search. Defaults to found items, since that
            // is what someone who has lost something wants.
            'status' => ['nullable', Rule::in(['lost', 'found'])],
        ];
    }
}
