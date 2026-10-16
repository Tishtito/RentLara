<?php

namespace App\Services\Meters;

use App\Enums\BillingType;
use App\Enums\LeaseStatus;
use App\Models\Lease;
use App\Models\User;
use App\Services\Properties\Visibility;
use App\Services\Rates\ChargeRateService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Meter readings to capture": one task per unit whose bill is due within the next few days and has
 * metered charges. Done/missing uses MeterReadingService::pendingReading(), the same rule billing uses.
 */
class ReadingTasks
{
    public function __construct(
        private MeterReadingService $readings,
        private ChargeRateService $rates,
        private Visibility $visibility,
    ) {}

    /**
     * @return Collection<int, array{unit_id: int, unit_number: string, property_id: int, property_name: string, tenant_name: string, lease_id: int, bills_on: string, done: bool, meters: array}>
     */
    /**
     * @param  int|null  $days  bills due within this many days; null = every running lease with meters
     * @param  string|null  $search  unit number or tenant name
     */
    public function due(User $user, ?int $propertyId = null, ?int $days = 7, ?CarbonImmutable $today = null, ?string $search = null): Collection
    {
        $today ??= CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $search = trim((string) $search);

        $leases = Lease::query()
            ->whereIn('status', LeaseStatus::billable())
            ->when($days !== null, fn (Builder $q) => $q->whereDate('next_billing_date', '<=', $today->addDays($days)->toDateString()))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereHas('unit', fn (Builder $u) => $u->where('unit_number', 'like', "%{$search}%"))
                ->orWhereHas('tenant', function (Builder $t) use ($search) {
                    foreach (preg_split('/\s+/', $search) as $word) {
                        $t->where(fn (Builder $n) => $n->where('first_name', 'like', "%{$word}%")->orWhere('last_name', 'like', "%{$word}%"));
                    }
                })))
            ->whereHas('unit', function (Builder $u) use ($user, $propertyId) {
                $u->when($propertyId, fn (Builder $q) => $q->where('property_id', $propertyId))
                    ->when(! $this->visibility->seesAllProperties($user), fn (Builder $q) => $q->whereHas('property', fn (Builder $p) => $p->where('caretaker_id', $user->id)))
                    ->whereHas('activeMeters');
            })
            ->with(['unit.property:id,name', 'unit.activeMeters', 'tenant:id,first_name,last_name'])
            ->orderBy('next_billing_date')
            ->get();

        return $leases->map(fn (Lease $lease) => $this->task($lease))->filter(fn ($t) => $t['meters'] !== [])->values();
    }

    /** @return array<string, mixed> */
    private function task(Lease $lease): array
    {
        $billingDay = $lease->next_billing_date->toImmutable(); // usage is billed up to the billing day
        $meters = [];

        foreach ($lease->unit->activeMeters as $meter) {
            $rate = $this->rates->resolve($lease->unit, $meter->charge_code, $billingDay);
            if ($rate && $rate->billing_type !== BillingType::Metered) {
                continue;
            }

            $pending = $this->readings->pendingReading($lease, $meter, $billingDay);
            $meters[] = [
                'meter_id' => $meter->id,
                'meter_number' => $meter->meter_number,
                'charge_code' => $meter->charge_code,
                'label' => $rate?->label ?? ucfirst($meter->charge_code),
                'unit_of_measure' => $rate?->unit_of_measure ?? '',
                'rate' => $rate?->rate ?? '0.00',
                'previous_reading' => $pending['from'],
                'previous_date' => $pending['previous']?->reading_date?->toDateString(),
                'current_reading' => $pending['current']?->reading,
                'current_date' => $pending['current']?->reading_date?->toDateString(),
            ];
        }

        return [
            'unit_id' => $lease->unit_id,
            'unit_number' => $lease->unit->unit_number,
            'property_id' => $lease->unit->property_id,
            'property_name' => $lease->unit->property->name,
            'tenant_name' => trim("{$lease->tenant->first_name} {$lease->tenant->last_name}"),
            'lease_id' => $lease->id,
            'bills_on' => $lease->next_billing_date->toDateString(),
            'done' => collect($meters)->every(fn ($m) => $m['current_reading'] !== null),
            'meters' => $meters,
        ];
    }
}
