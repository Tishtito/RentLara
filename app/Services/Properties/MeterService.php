<?php

namespace App\Services\Properties;

use App\Data\MeterData;
use App\Enums\BillingType;
use App\Models\ChargeRate;
use App\Models\Meter;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Meters fitted to units, one active meter per metered charge. A broken or swapped physical meter
 * is replaced: the old row is deactivated (its readings stay for old bills) and a new one starts
 * from its own initial reading. Readings are never edited.
 */
class MeterService
{
    public function save(MeterData $data): Meter
    {
        $unit = Unit::query()->findOrFail($data->unitId);

        $metered = ChargeRate::query()
            ->where('property_id', $unit->property_id)
            ->where(fn ($q) => $q->whereNull('unit_id')->orWhere('unit_id', $unit->id))
            ->where('charge_code', $data->chargeCode)
            ->where('billing_type', BillingType::Metered)
            ->exists();

        if (! $metered) {
            throw ValidationException::withMessages(['charge_code' => 'Add this charge as a metered rate in Billing settings first.']);
        }

        return DB::transaction(function () use ($data, $unit) {
            $active = $unit->meters()->where('charge_code', $data->chargeCode)->where('is_active', true)->lockForUpdate()->first();

            if ($active && $active->id !== $data->replacesMeterId) {
                throw ValidationException::withMessages(['charge_code' => "Unit {$unit->unit_number} already has an active {$data->chargeCode} meter. Replace it instead."]);
            }

            $active?->forceFill(['is_active' => false])->save();

            return $unit->meters()->create([
                'account_id' => $unit->account_id,
                'charge_code' => $data->chargeCode,
                'meter_number' => $data->meterNumber,
                'initial_reading' => $data->initialReading,
                'is_active' => true,
            ]);
        });
    }

    /**
     * Fit many meters on one property at once (meter setup for a new landlord): all or none.
     *
     * @param  list<MeterData>  $meters
     *
     * @throws ValidationException keyed meters.N.field (N = position in $meters)
     */
    public function fitMany(Property $property, array $meters): void
    {
        DB::transaction(function () use ($property, $meters) {
            foreach (array_values($meters) as $i => $data) {
                try {
                    $unit = Unit::query()->find($data->unitId);
                    if (! $unit || $unit->property_id !== $property->id) {
                        throw ValidationException::withMessages(['unit_id' => 'Choose a unit of this property.']);
                    }
                    $this->save($data);
                } catch (ValidationException $e) {
                    $messages = [];
                    foreach ($e->errors() as $field => $errors) {
                        $messages["meters.{$i}.{$field}"] = $errors;
                    }
                    throw ValidationException::withMessages($messages);
                }
            }
        });
    }

    /**
     * Meter setup grid for a property: every unit × each metered charge, with the active meter if fitted.
     * `fixed` lists the charges a unit pays a fixed amount for: no meter needed (not counted in `missing`).
     *
     * @return array{charges: list<array{charge_code: string, label: string, unit_of_measure: ?string}>, units: list<array<string, mixed>>, missing: int}
     */
    public function setup(Property $property): array
    {
        $charges = ChargeRate::query()->where('property_id', $property->id)->whereNull('unit_id')
            ->where('billing_type', BillingType::Metered)->whereNull('effective_to')
            ->orderBy('charge_code')->get(['charge_code', 'label', 'unit_of_measure']);
        $units = Unit::query()->where('property_id', $property->id)->with('activeMeters')->orderBy('unit_number')->get();
        // Utilities a unit pays a fixed amount for (now or from its next bill) need no meter.
        $fixed = ChargeRate::query()->where('property_id', $property->id)->whereNotNull('unit_id')
            ->where('billing_type', BillingType::Fixed)->whereNull('effective_to')
            ->get(['unit_id', 'charge_code'])->groupBy('unit_id')->map(fn ($rows) => $rows->pluck('charge_code')->unique()->values()->all());

        $missing = 0;
        $rows = $units->map(function (Unit $unit) use ($charges, $fixed, &$missing) {
            $meters = [];
            $unitFixed = $fixed->get($unit->id, []);
            foreach ($charges as $charge) {
                $meter = $unit->activeMeters->firstWhere('charge_code', $charge->charge_code);
                $missing += $meter || in_array($charge->charge_code, $unitFixed, true) ? 0 : 1;
                $meters[$charge->charge_code] = $meter ? [
                    'id' => $meter->id, 'meter_number' => $meter->meter_number, 'initial_reading' => $meter->initial_reading,
                ] : null;
            }

            return ['unit_id' => $unit->id, 'unit_number' => $unit->unit_number, 'status' => $unit->status, 'meters' => $meters, 'fixed' => array_values(array_intersect($unitFixed, $charges->pluck('charge_code')->all()))];
        })->values()->all();

        return [
            'charges' => $charges->map(fn ($c) => ['charge_code' => $c->charge_code, 'label' => $c->label, 'unit_of_measure' => $c->unit_of_measure])->values()->all(),
            'units' => $rows,
            'missing' => $missing,
        ];
    }
}
