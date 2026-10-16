<?php

namespace App\Http\Requests\Admin;

use App\Data\BillingRunData;
use App\Support\CurrentAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBillingRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Bill as of this date (defaults to today).
             *
             * @example 2026-10-01
             */
            'run_date' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    /** A super admin must pick a landlord with X-Account-Id; billing everyone at once isn't allowed here. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if (! app(CurrentAccount::class)->has()) {
                $validator->errors()->add('account', 'Choose an account with the X-Account-Id header.');
            }
        }];
    }

    public function toData(): BillingRunData
    {
        return new BillingRunData(
            accountId: app(CurrentAccount::class)->id(),
            runDate: $this->filled('run_date') ? CarbonImmutable::parse($this->input('run_date')) : CarbonImmutable::today(),
            trigger: BillingRunData::TRIGGER_MANUAL,
            triggeredBy: $this->user()->id,
        );
    }
}
