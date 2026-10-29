<?php

namespace App\Http\Requests\Items;

use Illuminate\Foundation\Http\FormRequest;

class RejectFoundItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller authorises against the specific item
    }

    public function rules(): array
    {
        return [
            // Required: the finder is told why their report was turned
            // away, and "rejected" with no explanation invites them to
            // simply file it again.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
