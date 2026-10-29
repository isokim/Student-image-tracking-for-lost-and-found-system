<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Public student registration.
 *
 * SECURITY: there is no `role` rule here, and that is deliberate. Because
 * the controller only ever uses validated() data, a request containing
 * "role":"admin" has that key dropped before it reaches the model - it is
 * not validated, so it is not returned. The User model's $fillable is the
 * second layer, and the controller assigning UserRole::Student explicitly
 * is the third. Any one of the three would stop the attack.
 */
class RegisterRequest extends FormRequest
{
    /** Anyone may attempt to register; there is nothing to authorise yet. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],

            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                'unique:users,email',
            ],

            'admissionNumber' => [
                'required',
                'string',
                'max:50',
                'unique:users,admission_number',
            ],

            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)->letters()->numbers(),
            ],
        ];
    }

    /**
     * The React app sends camelCase (`admissionNumber`,
     * `passwordConfirmation`) but Laravel's `confirmed` rule looks for
     * `password_confirmation`. Normalising here keeps the camelCase
     * contract in the API without fighting the framework's conventions.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('passwordConfirmation')) {
            $this->merge([
                'password_confirmation' => $this->input('passwordConfirmation'),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'admissionNumber.required' => 'Your admission number is required.',
            'admissionNumber.unique' => 'An account already exists for that admission number.',
            'password.confirmed' => 'The two passwords do not match.',
        ];
    }

    public function attributes(): array
    {
        return ['admissionNumber' => 'admission number'];
    }
}
