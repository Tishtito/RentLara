<?php

namespace App\Services\Properties;

use App\Data\PropertyData;
use App\Enums\LeaseStatus;
use App\Enums\UnitStatus;
use App\Enums\UserRole;
use App\Models\Lease;
use App\Models\Property;
use App\Models\User;
use App\Services\Leases\LeaseService;
use App\Services\Ledger\LeaseLedgerService;
use App\Support\CurrentAccount;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Landlords' properties (buildings / plots). Units, meters and rates hang off a property. */
class PropertyService
{
    public function __construct(
        private Visibility $visibility,
        private LeaseLedgerService $ledger,
        private LeaseService $leases,
    ) {}

    /**
     * Properties this login may see, with unit counts and the arrears owed across their leases.
     * Each property gets `units_count`, `occupied_count`, `vacant_count` and `arrears_total`.
     */
    public function list(User $user, ?Property $only = null): Collection
    {
        $properties = $this->visibility->properties($user)
            ->when($only, fn ($q) => $q->whereKey($only->id))
            ->with('caretaker:id,name,phone')
            ->withCount([
                'units',
                'units as occupied_count' => fn ($q) => $q->where('status', UnitStatus::Occupied),
                'units as vacant_count' => fn ($q) => $q->where('status', UnitStatus::Vacant),
            ])
            ->orderBy('name')
            ->get();

        $this->attachArrears($properties);

        $accountDay = $this->leases->billingDayFor((int) app(CurrentAccount::class)->id());
        foreach ($properties as $property) {
            $property->setAttribute('effective_billing_day', $property->billing_day ?? $accountDay);
        }

        return $properties;
    }

    /** One property with the same counts as list(). */
    public function find(User $user, Property $property): Property
    {
        return $this->list($user, $property)->first() ?? $property;
    }

    /** Active staff of this account (for "who looks after this property"). */
    public function caretakers(): \Illuminate\Support\Collection
    {
        return User::query()
            ->where('account_id', app(CurrentAccount::class)->id())
            ->where('role', UserRole::Staff)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'status']);
    }

    public function save(PropertyData $data): Property
    {
        $property = $data->propertyId ? Property::query()->findOrFail($data->propertyId) : new Property;

        $duplicate = Property::query()
            ->where('name', $data->name)
            ->when($property->exists, fn ($q) => $q->whereKeyNot($property->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'You already have a property with this name.']);
        }

        return DB::transaction(function () use ($property, $data) {
            $dayChanged = $property->exists && $property->billing_day !== $data->billingDay;
            $property->fill($data->attributes())->save();

            if ($dayChanged) {
                // Units with their own billing day keep it (reschedule resolves each lease's day again).
                $this->leases->reschedule(Lease::query()
                    ->whereIn('status', LeaseStatus::billable())
                    ->whereHas('unit', fn ($q) => $q->where('property_id', $property->id)->whereNull('billing_day'))
                    ->get());
            }

            return $property;
        });
    }

    /** arrears_total = sum of positive balances of the billable leases in each property. */
    private function attachArrears(Collection $properties): void
    {
        $leases = Lease::query()
            ->whereIn('status', LeaseStatus::billable())
            ->whereHas('unit', fn ($q) => $q->whereIn('property_id', $properties->modelKeys()))
            ->with('unit:id,property_id')
            ->get(['id', 'unit_id', 'opening_balance']);

        $balances = $this->ledger->balances($leases);
        $owed = [];
        foreach ($leases as $lease) {
            $cents = Money::toCents($balances[$lease->id] ?? 0);
            if ($cents > 0) {
                $owed[$lease->unit->property_id] = ($owed[$lease->unit->property_id] ?? 0) + $cents;
            }
        }

        foreach ($properties as $property) {
            $property->setAttribute('arrears_total', Money::fromCents($owed[$property->id] ?? 0));
        }
    }
}
