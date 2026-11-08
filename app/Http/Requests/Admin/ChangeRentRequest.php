<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ChangeRentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller checks the policy
    }

    public function rules(): array
    {
        return [
            /**
             * The new rent a month, in KES.
             *
             * @example 16000
             */
            'monthly_rent' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999'],
            /** Why the rent changed (kept in the rent history). */
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return ['monthly_rent' => 'monthly rent'];
    }

    public function monthlyRent(): string
    {
        return number_format((float) $this->input('monthly_rent'), 2, '.', '');
    }
}
