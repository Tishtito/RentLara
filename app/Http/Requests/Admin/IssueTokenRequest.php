<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class IssueTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** @example landlord@rentflow.test */
            'email' => ['required', 'string', 'email'],
            /** @example password */
            'password' => ['required', 'string'],
            /**
             * A name for the token so it can be recognised later, e.g. the client or device.
             *
             * @example scalar-docs
             */
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
