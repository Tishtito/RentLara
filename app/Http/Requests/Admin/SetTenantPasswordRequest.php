<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class SetTenantPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** The tenant's new portal password; tell them what it is. */
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }
}
