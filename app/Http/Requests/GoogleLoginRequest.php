<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** "Continue with Google" for both portals (session or token, web or app). */
class GoogleLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** The Google ID token (JWT) from Google Identity Services or the app's native Google sign-in. */
            'credential' => ['required', 'string', 'max:4096'],
            /** Tenant portal only: which home to log into, from a previous `homes` list. */
            'tenant_id' => ['sometimes', 'nullable', 'integer'],
            /** Session logins: keep me logged in after the browser closes. */
            'remember' => ['sometimes', 'boolean'],
            /**
             * Token logins: a name for the token, e.g. the phone model.
             *
             * @example Pixel 8
             */
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
