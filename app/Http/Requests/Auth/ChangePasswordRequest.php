<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/** Change your own password (admin and tenant portals). */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8), 'different:current_password'],
        ];
    }

    public function messages(): array
    {
        return ['password.different' => 'Choose a password that is different from your current one.'];
    }
}
