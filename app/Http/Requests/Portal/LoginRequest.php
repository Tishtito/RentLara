<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Email address or national ID number.
             *
             * @example jane@rentflow.test
             */
            'identifier' => ['required', 'string', 'max:255'],
            /** @example password */
            'password' => ['required', 'string'],
            /**
             * Which home to log into, from the `homes` list of a previous response. Only needed
             * when the person rents from more than one landlord.
             */
            'tenant_id' => ['sometimes', 'nullable', 'integer'],
            /** Keep me logged in after the browser closes. */
            'remember' => ['sometimes', 'boolean'],
        ];
    }
}
