<?php

namespace App\Http\Requests\Admin;

use App\Data\MeterData;
use Illuminate\Foundation\Http\FormRequest;

/** Meter setup: fit many meters on one property at once. */
class FitMetersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller checks the property policy
    }

    public function rules(): array
    {
        return [
            'meters' => ['required', 'array', 'min:1', 'max:500'],
            /** A unit of this property. */
            'meters.*.unit_id' => ['required', 'integer'],
            /**
             * A metered charge of the property.
             *
             * @example electricity
             */
            'meters.*.charge_code' => ['required', 'string', 'max:30'],
            /** @example KPLC-0012345 */
            'meters.*.meter_number' => ['nullable', 'string', 'max:50'],
            /**
             * What the meter shows today (usage is counted from here).
             *
             * @example 1520
             */
            'meters.*.initial_reading' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999'],
        ];
    }

    /** @return list<MeterData> */
    public function toData(): array
    {
        return array_map(fn (array $m) => new MeterData(
            unitId: (int) $m['unit_id'],
            chargeCode: (string) $m['charge_code'],
            initialReading: (string) $m['initial_reading'],
            meterNumber: ($m['meter_number'] ?? null) ?: null,
        ), array_values($this->input('meters', [])));
    }
}
