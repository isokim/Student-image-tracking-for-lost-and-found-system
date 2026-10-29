<?php

namespace App\Http\Requests\Claims;

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

            // Optional: the AI suggestion that prompted the claim.
            'matchId' => ['nullable', Rule::exists('item_matches', 'id')],

            // The claimant's proof-of-ownership narrative. A meaningful
            // minimum length, because "it's mine" is not evidence staff
            // can act on.
            'identifyingInformation' => ['required', 'string', 'min:20', 'max:2000'],

            'verificationAnswers' => ['required', 'array', 'min:1', 'max:10'],
            'verificationAnswers.*.question' => ['required', 'string', 'max:255'],
            'verificationAnswers.*.answer' => ['required', 'string', 'max:1000'],

            /**
             * Note what is NOT accepted here: expectedAnswer, result, or
             * status. A claimant supplying their own "expected answer" or
             * posting status=approved would defeat the whole review step,
             * so those keys are never validated and therefore never reach
             * the model - reinforced by Claim::$fillable.
             */
        ];
    }

    public function messages(): array
    {
        return [
            'identifyingInformation.min' => 'Please describe the item in enough detail for staff to confirm it is yours.',
            'verificationAnswers.required' => 'Please answer the verification questions.',
        ];
    }

    public function attributes(): array
    {
        return [
            'foundItemId' => 'item',
            'identifyingInformation' => 'identifying information',
        ];
    }
}
