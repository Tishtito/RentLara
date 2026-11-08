<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Email or national ID, as on the login page.
             *
             * @example jane@rentflow.test
             */
            'identifier' => ['required', 'string', 'max:255'],
        ];
    }
}
