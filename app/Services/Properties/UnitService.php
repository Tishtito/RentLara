<?php

namespace App\Services\Properties;

use App\Data\UnitData;
use App\Enums\LeaseStatus;
use App\Enums\UnitStatus;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Services\Leases\LeaseService;
use App\Services\Ledger\LeaseLedgerService;
use App\Services\Rates\ChargeRateService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Units in a property. A unit's status is "occupied" only while it has a lease: move-in and move-out
 * (MoveInService / LeaseService::end) set it; editing a unit can't. Each metered utility is billed by meter
 * or a fixed monthly amount per unit (ChargeRateService::setUnitBilling), from the unit's next bill.
 */
class UnitService
{
    public function __construct(
        private LeaseLedgerService $ledger,
        private ChargeRateService $rates,
        private LeaseService $leases,
    ) {}

    /**
     * A property's units with their current lease (tenant, rent, next bill), its running balance
     * (`current_lease.balance`), active meters and how each metered utility is billed (`utilities`).
     */
    public function forProperty(Property $property): Collection
    {
        $units = $property->units()
            ->with(['currentLease.tenant:id,first_name,last_name,phone,email,status', 'activeMeters'])
            ->orderByRaw('LENGTH(unit_number)')
            ->orderBy('unit_number')
            ->get();

        $leases = $units->pluck('currentLease')->filter();
        $balances = $this->ledger->balances($leases);
        foreach ($leases as $lease) {
            $lease->setAttribute('balance', $balances[$lease->id]);
        }

        $utilities = $this->rates->unitBillings($property, $units);
        $inherited = $property->billing_day ?? $this->leases->billingDayFor((int) $property->account_id);
        foreach ($units as $unit) {
            $unit->setAttribute('utilities', $utilities[$unit->id] ?? []);
            $unit->setAttribute('effective_billing_day', $unit->billing_day ?? $inherited);
        }

        return $units;
    }

    public function save(UnitData $data): Unit
    {
        return DB::transaction(function () use ($data) {
            $before = $data->unitId ? Unit::query()->whereKey($data->unitId)->value('billing_day') : null;
            $dayBefore = $before === null ? null : (int) $before;
            $unit = $this->saveDetails($data);

            if ($data->billingDaySet && $data->unitId && $dayBefore !== $data->billingDay) {
                $this->leases->reschedule(Lease::query()->where('unit_id', $unit->id)->whereIn('status', LeaseStatus::billable())->get());
            }

            if ($data->utilities !== null) {
                $this->rates->setUnitBilling($unit, $data->utilities, $this->rates->unitChangeDate($unit), $data->createdBy);
            }

            return $unit;
        });
    }

    private function saveDetails(UnitData $data): Unit
    {
        $unit = $data->unitId ? Unit::query()->findOrFail($data->unitId) : new Unit(['property_id' => $data->propertyId, 'status' => UnitStatus::Vacant]);

        $duplicate = Unit::query()
            ->where('property_id', $unit->property_id)
            ->where('unit_number', $data->unitNumber)
            ->when($unit->exists, fn ($q) => $q->whereKeyNot($unit->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['unit_number' => 'This property already has a unit with this number.']);
        }

        if ($data->status) {
            $occupied = $unit->exists && $unit->status === UnitStatus::Occupied;
            if ($data->status === UnitStatus::Occupied && ! $occupied) {
                throw ValidationException::withMessages(['status' => 'Use "Move in" to give a unit a tenant.']);
            }
            if ($occupied && $data->status !== UnitStatus::Occupied) {
                throw ValidationException::withMessages(['status' => 'End the lease (move out) before changing an occupied unit.']);
            }
            $unit->status = $data->status;
        }

        $unit->fill([
            'unit_number' => $data->unitNumber,
            'type' => $data->type,
            'bedrooms' => $data->bedrooms,
            'floor' => $data->floor,
            'default_rent' => $data->defaultRent,
            'default_deposit' => $data->defaultDeposit,
            'notes' => $data->notes,
        ]);
        if ($data->billingDaySet) {
            $unit->billing_day = $data->billingDay;
        }
        $unit->save();

        return $unit;
    }
}
