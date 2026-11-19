<?php

namespace App\Http\Requests\Claims;

use App\Enums\LostItemStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        // "May I make claims at all". Whether this user may claim THIS
        // item is a separate question, answered by FoundItemPolicy::claim
        // in the controller once the item has been resolved.
        return $this->user()->can('create', \App\Models\Claim::class);
    }

    public function rules(): array
    {
        return [
            'foundItemId' => ['required', Rule::exists('found_items', 'id')->whereNull('deleted_at')],

        
            'matchId' => ['nullable', Rule::exists('item_matches', 'id')],

        
            'lostItemId' => [
                'nullable',
                Rule::exists('lost_items', 'id')
                    ->where('user_id', $this->user()->id)
                    ->whereIn('status', array_map(
                        fn (LostItemStatus $s) => $s->value,
                        array_filter(LostItemStatus::cases(), fn (LostItemStatus $s) => $s->isOpen()),
                    ))
                    ->whereNull('deleted_at'),
            ],

          
            'identifyingInformation' => ['required', 'string', 'min:20', 'max:2000'],

            
            'verificationAnswers' => ['sometimes', 'nullable', 'array', 'max:10'],
            'verificationAnswers.*.question' => ['required', 'string', 'max:255'],
            'verificationAnswers.*.answer' => ['required', 'string', 'max:1000'],

           
        ];
    }



    public function messages(): array
    {
        return [
            'identifyingInformation.min' => 'Please give at least 20 characters of detail that proves the item is yours.',
            'lostItemId.exists' => 'Choose one of your own open lost reports, or say you did not report it lost.',
        ];
    }

    public function attributes(): array
    {
        return [
            'foundItemId' => 'item',
            'lostItemId' => 'lost report',
            'identifyingInformation' => "details that prove it's yours",
        ];
    }
}
