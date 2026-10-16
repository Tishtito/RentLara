<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
            /**
             * Apps only: name for the API token. Without it the browser gets a cookie session.
             *
             * @example Pixel 8
             */
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
