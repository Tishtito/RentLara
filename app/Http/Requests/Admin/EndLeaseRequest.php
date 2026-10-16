<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class EndLeaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller checks the policy
    }

    public function rules(): array
    {
        return [
            /**
             * The tenant's last day in the unit.
             *
             * @example 2026-12-31
             */
            'end_date' => ['required', 'date'],
        ];
    }
}
