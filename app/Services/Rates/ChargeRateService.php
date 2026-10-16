<?php

namespace App\Services\Rates;

use App\Data\ChangeChargeRateData;
use App\Enums\BillingType;
use App\Events\ChargeRateChanged;
use App\Models\ChargeRate;
use App\Models\Property;
use App\Models\Unit;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Landlord billing settings. Rates are VERSIONED, NEVER EDITED (confirmed decision #2):
 * this service is the only writer, and the only change it makes to an existing row is
 * closing it (effective_to). Old bills therefore always match their rate.
 */
class ChargeRateService
{
    /**
     * Set a new price from a date: closes the current version and opens a new one.
     * The first call for a charge simply creates it.
     */
    public function change(ChangeChargeRateData $data): ChargeRate
    {
        $property = Property::query()->findOrFail($data->propertyId);

        [$rate, $previous] = DB::transaction(function () use ($data, $property) {
            $current = ChargeRate::query()
                ->where('property_id', $property->id)
                ->where('unit_id', $data->unitId)
                ->where('charge_code', $data->chargeCode)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();

            if ($current && $current->effective_from->gte($data->effectiveFrom)) {
                throw ValidationException::withMessages([
                    'effective_from' => 'The new price must start after '.$current->effective_from->format('j M Y').', when the current price started.',
                ]);
            }

            // A unit's first override copies label/type/unit from the property-wide charge.
            $template = $current ?? ($data->unitId ? ChargeRate::query()
                ->where('property_id', $property->id)
                ->whereNull('unit_id')
                ->where('charge_code', $data->chargeCode)
                ->whereNull('effective_to')
                ->first() : null);

            if (! $template && ! $data->billingType) {
                throw ValidationException::withMessages(['billing_type' => 'Choose metered or fixed for a new charge.']);
            }

            $current?->forceFill(['effective_to' => $data->effectiveFrom->subDay()->toDateString()])->save();

            $rate = ChargeRate::create([
                'account_id' => $property->account_id,
                'property_id' => $property->id,
                'unit_id' => $data->unitId,
                'charge_code' => $data->chargeCode,
                'label' => $data->label ?? $template?->label ?? ucfirst($data->chargeCode),
                'billing_type' => $data->billingType ?? $template?->billing_type ?? BillingType::Fixed,
                'unit_of_measure' => $data->unitOfMeasure ?? $template?->unit_of_measure,
                'rate' => $data->rate,
                'effective_from' => $data->effectiveFrom->toDateString(),
                'effective_to' => null,
                'created_by' => $data->createdBy,
            ]);

            return [$rate, $current];
        });

        ChargeRateChanged::dispatch($rate, $previous);

        return $rate;
    }

    /** Every version of every charge of a property (property-wide and unit overrides), newest first. */
    public function history(Property $property): Collection
    {
        return ChargeRate::query()
            ->where('property_id', $property->id)
            ->with(['createdBy:id,name', 'unit:id,unit_number'])
            ->orderBy('charge_code')
            ->orderByRaw('unit_id IS NOT NULL')
            ->orderByDesc('effective_from')
            ->get();
    }

    /**
     * The rate that applies to a unit for one charge on a date:
     * a unit-specific override wins over the property-wide rate.
     */
    public function resolve(Unit $unit, string $chargeCode, CarbonInterface $date): ?ChargeRate
    {
        return $this->applicableTo($unit, $date)->where('charge_code', $chargeCode)->first();
    }

    /** Every rate that applies to a unit on a date, one per charge_code, overrides taking precedence. */
    public function allFor(Unit $unit, CarbonInterface $date): Collection
    {
        return $this->applicableTo($unit, $date)->get()->unique('charge_code')->values();
    }

    private function applicableTo(Unit $unit, CarbonInterface $date): Builder
    {
        $day = $date->toDateString();

        return ChargeRate::query()
            ->where('property_id', $unit->property_id)
            ->where(fn ($q) => $q->whereNull('unit_id')->orWhere('unit_id', $unit->id))
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->orderByRaw('unit_id IS NULL')   // override (non-null unit_id) first
            ->orderByDesc('effective_from');
    }
}
