<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller checks "roles.manage"
    }

    public function rules(): array
    {
        return [
            /** @example Meter reader */
            'name' => ['required', 'string', 'max:60'],
            /**
             * Permission names (GET /api/admin/permissions).
             *
             * @example ["readings.view", "readings.record"]
             */
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(Permission::values())],
        ];
    }
}
