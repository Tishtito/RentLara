<?php

namespace App\Http\Requests\Admin;

use App\Data\MoveInData;
use App\Support\CurrentAccount;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller checks the policy
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('tenant.email')) {
            $this->merge(['tenant' => [...$this->input('tenant'), 'email' => mb_strtolower(trim((string) $this->input('tenant.email')))]]);
        }
    }

    public function rules(): array
    {
        $account = app(CurrentAccount::class)->id();
        $uniqueInAccount = fn (string $column) => Rule::unique('tenants', $column)->where('account_id', $account)->whereNull('deleted_at');

        return [
            /** @example Jane */
            'tenant.first_name' => ['required', 'string', 'max:80'],
            /** @example Wanjiru */
            'tenant.last_name' => ['required', 'string', 'max:80'],
            /**
             * Kenyan mobile number (bills by SMS / WhatsApp).
             *
             * @example 0712 345 678
             */
            'tenant.phone' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail) {
                if (! Phone::toE164((string) $value)) {
                    $fail('Enter a Kenyan mobile number, e.g. 0712 345 678.');
                }
            }],
            /** The portal invitation is emailed here. */
            'tenant.email' => ['nullable', 'email', 'max:255', $uniqueInAccount('email')],
            /** Lets the tenant log in with their ID number. */
            'tenant.national_id' => ['nullable', 'string', 'max:20', $uniqueInAccount('national_id')],
            /** @example 15000 */
            'lease.monthly_rent' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999'],
            'lease.deposit_amount' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999'],
            /**
             * Move-in date. A new tenant gets a move-in bill (deposit + rent up to the next billing day); for a
             * tenant already living there (`existing`) it's the date they originally moved in.
             *
             * @example 2026-09-01
             */
            'lease.start_date' => ['required', 'date'],
            /**
             * The tenant already lives here (the landlord is joining Rentflow): no move-in bill, the deposit is
             * already held, the opening readings are today's, and billing starts on the next billing day.
             */
            'lease.existing' => ['nullable', 'boolean'],
            /**
             * Existing tenants only: the first Rentflow bill (default the next billing day).
             *
             * @example 2026-11-05
             */
            'lease.first_bill_date' => ['nullable', 'date', 'after_or_equal:today', 'prohibited_unless:lease.existing,true,1'],
            /** Arrears brought in from before Rentflow (negative = credit). */
            'lease.opening_balance' => ['nullable', 'decimal:0,2', 'min:-9999999999', 'max:9999999999'],
            /** Meter id → reading on the move-in date, one for every active meter of the unit. */
            'opening_readings' => ['nullable', 'array'],
            'opening_readings.*' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'tenant.email.unique' => 'You already have a tenant with this email.',
            'tenant.national_id.unique' => 'You already have a tenant with this national ID.',
        ];
    }

    public function attributes(): array
    {
        return [
            'tenant.first_name' => 'first name', 'tenant.last_name' => 'last name', 'tenant.phone' => 'phone',
            'tenant.email' => 'email', 'tenant.national_id' => 'national ID', 'lease.monthly_rent' => 'monthly rent',
            'lease.deposit_amount' => 'deposit', 'lease.start_date' => 'move-in date', 'lease.opening_balance' => 'opening arrears',
        ];
    }

    public function toData(int $unitId): MoveInData
    {
        $money = fn (?string $v) => number_format((float) ($v ?? 0), 2, '.', '');

        return new MoveInData(
            unitId: $unitId,
            tenant: [
                'first_name' => trim((string) $this->input('tenant.first_name')),
                'last_name' => trim((string) $this->input('tenant.last_name')),
                'phone' => Phone::toE164((string) $this->input('tenant.phone')),
                'email' => $this->input('tenant.email') ?: null,
                'national_id' => trim((string) $this->input('tenant.national_id')) ?: null,
            ],
            monthlyRent: $money($this->input('lease.monthly_rent')),
            depositAmount: $money($this->input('lease.deposit_amount')),
            openingBalance: $money($this->input('lease.opening_balance')),
            startDate: CarbonImmutable::parse($this->input('lease.start_date')),
            existing: $this->boolean('lease.existing'),
            firstBillDate: $this->filled('lease.first_bill_date') ? CarbonImmutable::parse($this->input('lease.first_bill_date')) : null,
            openingReadings: collect($this->input('opening_readings', []))
                ->filter(fn ($v) => $v !== null && $v !== '')
                ->map(fn ($v) => number_format((float) $v, 2, '.', ''))
                ->all(),
            createdBy: $this->user()?->id,
        );
    }
}
