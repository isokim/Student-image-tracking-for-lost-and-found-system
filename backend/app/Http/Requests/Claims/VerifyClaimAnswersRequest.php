<?php

namespace App\Http\Requests\Claims;

use App\Enums\VerificationResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A reviewer marking each verification answer pass or fail.
 *
 * Separate from approving the claim, so the record shows which specific
 * answers convinced them - useful if a decision is ever questioned.
 */
class VerifyClaimAnswersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller authorises against the specific claim
    }

    public function rules(): array
    {
        return [
            'results' => ['required', 'array', 'min:1'],
            'results.*.id' => ['required', Rule::exists('claim_verifications', 'id')],
            'results.*.result' => ['required', Rule::enum(VerificationResult::class)],
        ];
    }
}
