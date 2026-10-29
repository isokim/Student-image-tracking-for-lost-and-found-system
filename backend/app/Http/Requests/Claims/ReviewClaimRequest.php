<?php

namespace App\Http\Requests\Claims;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff approving or rejecting a claim.
 *
 * Notes are optional on approval and required on rejection, which is
 * why the rule is built from the route rather than being a fixed list:
 * a student turned down deserves a reason.
 */
class ReviewClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller authorises against the specific claim
    }

    public function rules(): array
    {
        $rejecting = $this->routeIs('*.reject');

        return [
            'notes' => [
                $rejecting ? 'required' : 'nullable',
                'string',
                $rejecting ? 'min:5' : 'min:0',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'notes.required' => 'Please explain why this claim is being rejected.',
        ];
    }
}
