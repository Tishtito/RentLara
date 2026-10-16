<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class SetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** From the emailed link. */
            'email' => ['required', 'email'],
            /** From the emailed link. */
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
            /** Apps only: name for an API token. Without it the browser gets a cookie session. */
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
