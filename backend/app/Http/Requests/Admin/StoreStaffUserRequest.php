<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Provisioning a staff or admin account.
 *
 * This is the one request in the application that accepts a `role`, and
 * it is only reachable behind UserPolicy::create (admins only). Contrast
 * RegisterRequest, which has no role rule at all.
 *
 * Note that "student" is not an allowed value here: students come in
 * through public registration with their admission number, and creating
 * one by hand would produce an account with no admission number.
 */
class StoreStaffUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\User::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],

            'role' => ['required', Rule::in([UserRole::Staff->value, UserRole::Admin->value])],

            'departmentId' => ['required', Rule::exists('departments', 'id')],

            // Same strength rules as public registration. A staff account
            // is a more valuable target, not a less one.
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('passwordConfirmation')) {
            $this->merge(['password_confirmation' => $this->input('passwordConfirmation')]);
        }
    }

    public function attributes(): array
    {
        return ['departmentId' => 'department'];
    }
}
