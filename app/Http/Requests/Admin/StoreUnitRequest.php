<?php

namespace App\Http\Requests\Admin;

use App\Data\UnitData;
use App\Data\UnitUtilityData;
use App\Enums\BillingType;
use App\Enums\UnitStatus;
use App\Models\ChargeRate;
use App\Models\Property;
use App\Models\Unit;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Add a unit to a property (POST) or edit one (PUT /units/{unit}). */
class StoreUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller checks the policy
    }

    public function rules(): array
    {
        return [
            /**
             * Unit / house number as written on the door and the bills.
             *
             * @example 7-7
             */
            'unit_number' => ['required', 'string', 'max:30'],
            /** @example 1BR */
            'type' => ['nullable', 'string', 'max:30'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'floor' => ['nullable', 'integer', 'min:0', 'max:200'],
            /**
             * Asking rent in KES; pre-fills move-in (the lease keeps its own rent).
             *
             * @example 15000
             */
            'default_rent' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'default_deposit' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999'],
            /** vacant, reserved or maintenance. "occupied" is set by move-in only. */
            'status' => ['sometimes', Rule::enum(UnitStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
            /**
             * Day of the month (1–28) this unit bills on; null = its property's (else the account's). Omit to
             * leave it unchanged. Changing it for a let unit moves the next bill to the first new billing day on
             * or after it, and that bill also charges the days in between (rent × days ÷ 30).
             *
             * @example 1
             */
            'billing_day' => ['sometimes', 'nullable', 'integer', 'between:1,28'],
            /**
             * How the property's metered utilities are billed for this unit: by meter (usage × the property's
             * rate) or a fixed amount every month. Only the utilities sent change; omit to leave them as they are.
             * An occupied unit changes from its next bill, a vacant one from today.
             */
            'utilities' => ['sometimes', 'array'],
            /**
             * A metered charge of this property (see its charge rates).
             *
             * @example water
             */
            'utilities.*.charge_code' => ['required', 'string', 'distinct', function (string $attribute, mixed $value, Closure $fail) {
                $metered = ChargeRate::query()->where('property_id', $this->propertyId())->whereNull('unit_id')
                    ->where('charge_code', $value)->where('billing_type', BillingType::Metered)->whereNull('effective_to')->exists();
                if (! $metered) {
                    $fail('Choose a metered charge of this property.');
                }
            }],
            /** metered (by meter readings) or fixed (a flat amount every month, no readings). */
            'utilities.*.billing_type' => ['required', Rule::enum(BillingType::class)],
            /**
             * Fixed only: KES charged every month instead of the meter.
             *
             * @example 500
             */
            'utilities.*.amount' => ['nullable', 'required_if:utilities.*.billing_type,fixed', 'decimal:0,2', 'min:0', 'max:9999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'utilities.*.amount.required_if' => 'Enter the fixed amount per month.',
            'billing_day.between' => 'Choose a day from 1 to 28 (it must exist in every month).',
        ];
    }

    private function propertyId(): ?int
    {
        $property = $this->route('property');
        $unit = $this->route('unit');

        return $property instanceof Property ? $property->id : ($unit instanceof Unit ? $unit->property_id : null);
    }

    public function toData(int $propertyId, ?Unit $unit = null): UnitData
    {
        return new UnitData(
            propertyId: $propertyId,
            unitNumber: trim((string) $this->input('unit_number')),
            defaultRent: number_format((float) $this->input('default_rent'), 2, '.', ''),
            defaultDeposit: number_format((float) $this->input('default_deposit', 0), 2, '.', ''),
            type: $this->input('type'),
            bedrooms: $this->filled('bedrooms') ? $this->integer('bedrooms') : null,
            floor: $this->filled('floor') ? $this->integer('floor') : null,
            status: $this->filled('status') ? UnitStatus::from($this->input('status')) : null,
            notes: $this->input('notes'),
            unitId: $unit?->id,
            utilities: $this->has('utilities') ? array_map(fn (array $u) => new UnitUtilityData(
                chargeCode: (string) $u['charge_code'],
                billingType: BillingType::from($u['billing_type']),
                amount: $u['billing_type'] === BillingType::Fixed->value ? number_format((float) $u['amount'], 2, '.', '') : null,
            ), array_values((array) $this->input('utilities', []))) : null,
            createdBy: $this->user()?->id,
            billingDay: $this->filled('billing_day') ? $this->integer('billing_day') : null,
            billingDaySet: $this->has('billing_day'),
        );
    }
}
