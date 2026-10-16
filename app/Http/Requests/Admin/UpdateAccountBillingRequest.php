<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountBillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller checks the policy
    }

    public function rules(): array
    {
        return [
            /**
             * Day of the month every lease is billed (1–28, so it exists in every month).
             *
             * @example 5
             */
            'billing_day' => ['required', 'integer', 'min:1', 'max:28'],
            /**
             * Due date = bill date + N days.
             *
             * @example 5
             */
            'payment_due_days' => ['required', 'integer', 'min:0', 'max:60'],
            /**
             * Start of bill numbers, e.g. INV → INV-2026-000001.
             *
             * @example INV
             */
            'invoice_prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
        ];
    }
}
