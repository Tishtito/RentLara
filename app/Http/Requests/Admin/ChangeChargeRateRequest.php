<?php

namespace App\Http\Requests\Admin;

use App\Data\ChangeChargeRateData;
use App\Enums\BillingType;
use App\Models\Property;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeChargeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller checks the policy
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('charge_code')) {
            $this->merge(['charge_code' => str((string) $this->input('charge_code'))->trim()->lower()->snake()->toString()]);
        }
    }

    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');

        return [
            /**
             * Short code for the charge; meters and bills use it. Lowercase, e.g. electricity, water, garbage, security.
             *
             * @example electricity
             */
            'charge_code' => ['required', 'string', 'max:30', 'regex:/^[a-z][a-z0-9_]*$/'],
            /**
             * Shown on the bill. Required for a new charge; defaults to the current label.
             *
             * @example Electricity
             */
            'label' => ['nullable', 'string', 'max:60'],
            /** metered (usage × rate, needs meters) or fixed (flat amount every cycle). Required for a new charge. */
            'billing_type' => ['nullable', Rule::enum(BillingType::class)],
            /**
             * Metered charges: what one unit is.
             *
             * @example kWh
             */
            'unit_of_measure' => ['nullable', 'string', 'max:20'],
            /**
             * KES per unit (metered) or per cycle (fixed).
             *
             * @example 30
             */
            'rate' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999'],
            /**
             * From this date bills use the new price; earlier bills keep the old one.
             *
             * @example 2026-11-01
             */
            'effective_from' => ['required', 'date'],
            /** Only for this unit (an override); empty = every unit in the property. */
            'unit_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($property) {
                if (! Unit::query()->whereKey((int) $value)->where('property_id', $property->id)->exists()) {
                    $fail('Choose a unit of this property.');
                }
            }],
        ];
    }

    public function toData(Property $property): ChangeChargeRateData
    {
        return new ChangeChargeRateData(
            propertyId: $property->id,
            chargeCode: (string) $this->input('charge_code'),
            rate: number_format((float) $this->input('rate'), 2, '.', ''),
            effectiveFrom: CarbonImmutable::parse($this->input('effective_from')),
            unitId: $this->filled('unit_id') ? $this->integer('unit_id') : null,
            label: $this->input('label') ?: null,
            billingType: $this->filled('billing_type') ? BillingType::from($this->input('billing_type')) : null,
            unitOfMeasure: $this->input('unit_of_measure') ?: null,
            createdBy: $this->user()?->id,
        );
    }
}
