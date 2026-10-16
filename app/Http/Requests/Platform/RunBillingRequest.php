<?php

namespace App\Http\Requests\Platform;

use App\Data\BillingRunData;
use App\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RunBillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // super admins only (route middleware)
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

    /** Only active accounts are billed. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $account = Account::query()->find((int) $this->route('account'));
            if (! $account) {
                abort(404, 'Account not found.');
            }
            if (! $account->isActive()) {
                $validator->errors()->add('account', 'This account is '.$account->status.'; reactivate it before billing.');
            }
        }];
    }

    public function toData(): BillingRunData
    {
        return new BillingRunData(
            accountId: (int) $this->route('account'),
            runDate: $this->filled('run_date') ? CarbonImmutable::parse($this->input('run_date')) : CarbonImmutable::today(),
            trigger: BillingRunData::TRIGGER_MANUAL,
            triggeredBy: $this->user()->id,
        );
    }
}
