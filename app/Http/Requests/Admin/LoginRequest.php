<?php

namespace App\Http\Requests\Admin;

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
            /** @example landlord@rentflow.test */
            'email' => ['required', 'string', 'email'],
            /** @example password */
            'password' => ['required', 'string'],
            /** Keep me logged in after the browser closes. */
            'remember' => ['sometimes', 'boolean'],
        ];
    }
}
