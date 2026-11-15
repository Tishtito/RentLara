<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ExcludeChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller checks the policy
    }

    public function rules(): array
    {
        return [
            /**
             * The charge to excuse the tenant from (a charge of their house, e.g. garbage or water).
             *
             * @example garbage
             */
            'charge_code' => ['required', 'string', 'max:30'],
            /** Why (kept in the history and the activity log). */
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
