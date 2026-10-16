<?php

namespace App\Http\Requests\Platform;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // super admins only (route middleware)
    }

    public function rules(): array
    {
        return [
            /** Permission names; replaces the template's permissions in every account. */
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(Permission::values())],
        ];
    }
}
