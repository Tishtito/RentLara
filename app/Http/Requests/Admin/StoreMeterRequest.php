<?php

namespace App\Http\Requests\Admin;

use App\Data\MeterData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Fit a meter to a unit (POST), or replace one (PUT /meters/{meter}: same charge, new meter). */
class StoreMeterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller checks the policy
    }

    public function rules(): array
    {
        return [
            /**
             * The metered charge this meter measures (a metered rate in Billing settings).
             *
             * @example electricity
             */
            'charge_code' => [Rule::requiredIf($this->isMethod('post')), 'string', 'max:30'],
            /** @example KPLC-0012345 */
            'meter_number' => ['nullable', 'string', 'max:50'],
            /**
             * What the meter shows now (the first bill counts usage from here).
             *
             * @example 100
             */
            'initial_reading' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999'],
        ];
    }

    public function toData(int $unitId, ?string $chargeCode = null, ?int $replacesMeterId = null): MeterData
    {
        return new MeterData(
            unitId: $unitId,
            chargeCode: $chargeCode ?? (string) $this->input('charge_code'),
            initialReading: number_format((float) $this->input('initial_reading'), 2, '.', ''),
            meterNumber: $this->input('meter_number'),
            replacesMeterId: $replacesMeterId,
        );
    }
}
