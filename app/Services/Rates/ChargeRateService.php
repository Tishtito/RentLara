<?php

namespace App\Services\Rates;

use App\Data\ChangeChargeRateData;
use App\Data\UnitUtilityData;
use App\Enums\BillingType;
use App\Enums\LeaseStatus;
use App\Events\ChargeRateChanged;
use App\Models\Account;
use App\Models\ChargeRate;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Support\Money;
use Carbon\CarbonImmutable;
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

    /**
     * The charges a monthly bill on $billingDay carries. Fixed lines bill the COMING month, metered lines
     * the PAST month's usage, so when a unit switches between the two on a billing day:
     *   metered → fixed: that bill has the last usage AND the first fixed amount;
     *   fixed → metered: that bill has neither (the last fixed amount covered the month), usage starts next bill.
     *
     * @return array{usage: Collection<int, ChargeRate>, fixed: Collection<int, ChargeRate>}
     */
    public function forBill(Unit $unit, CarbonInterface $billingDay): array
    {
        $now = $this->allFor($unit, $billingDay)->keyBy('charge_code');
        $before = $this->allFor($unit, CarbonImmutable::parse($billingDay->toDateString())->subDay())->keyBy('charge_code');

        $usage = new Collection;
        foreach ($now as $code => $rate) {
            if ($rate->isMetered() && ! ($before->has($code) && ! $before[$code]->isMetered())) {
                $usage->push($rate);
            } elseif (! $rate->isMetered() && $before->has($code) && $before[$code]->isMetered()) {
                $usage->push($before[$code]); // final usage, at the metered price
            }
        }

        // Oldest charge first (electricity before water on Kims bills), whichever version is billed.
        return ['usage' => $usage->sortBy('id')->values(), 'fixed' => $now->reject(fn (ChargeRate $r) => $r->isMetered())->values()];
    }

    /**
     * When metering of a charge last restarted for a unit after a fixed spell (the day after the unit's fixed
     * price ended), on or before $date. Usage before it was covered by the fixed amount.
     */
    public function meteredSince(Unit $unit, string $chargeCode, CarbonInterface $date): ?CarbonImmutable
    {
        $end = ChargeRate::query()
            ->where('unit_id', $unit->id)
            ->where('charge_code', $chargeCode)
            ->where('billing_type', BillingType::Fixed)
            ->whereNotNull('effective_to')
            ->whereDate('effective_to', '<', $date->toDateString())
            ->max('effective_to');

        return $end ? CarbonImmutable::parse($end)->addDay() : null;
    }

    /**
     * The date a change to a unit's utility billing takes effect: its tenant's next bill, or today when vacant.
     */
    public function unitChangeDate(Unit $unit): CarbonImmutable
    {
        $next = Lease::query()->where('unit_id', $unit->id)->whereIn('status', LeaseStatus::billable())
            ->orderByDesc('start_date')->orderByDesc('id')->value('next_billing_date');

        return $next ? CarbonImmutable::parse($next) : $this->today((int) $unit->account_id);
    }

    /**
     * Bill a unit's metered utilities by meter or a fixed monthly amount, from $from (unitChangeDate).
     * Fixed = a fixed unit override of the property's metered charge; by meter = no fixed override, so the
     * property price (or a metered unit price) applies. Versions no bill used and not started yet are
     * discarded rather than stacked, so a typo can be fixed the same day; billed versions never change.
     *
     * @param  list<UnitUtilityData>  $utilities
     */
    public function setUnitBilling(Unit $unit, array $utilities, CarbonImmutable $from, ?int $createdBy = null): void
    {
        foreach ($utilities as $index => $utility) {
            if ($this->billedAs($unit, $utility, $from)) {
                continue;
            }

            $this->undoPending($unit, $utility->chargeCode);

            if ($this->billedAs($unit, $utility, $from)) {
                continue;
            }

            if ($utility->billingType === BillingType::Fixed) {
                $property = ChargeRate::query()->where('property_id', $unit->property_id)->whereNull('unit_id')
                    ->where('charge_code', $utility->chargeCode)->whereNull('effective_to')->first();

                try {
                    $this->change(new ChangeChargeRateData(
                        propertyId: $unit->property_id,
                        chargeCode: $utility->chargeCode,
                        rate: (string) $utility->amount,
                        effectiveFrom: $from,
                        unitId: $unit->id,
                        label: $property?->label,
                        billingType: BillingType::Fixed,
                        createdBy: $createdBy,
                    ));
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(["utilities.{$index}.billing_type" => collect($e->errors())->flatten()->first()]);
                }

                continue;
            }

            // By meter: end the unit's fixed price the day before; the metered price applies from $from.
            ChargeRate::query()->where('unit_id', $unit->id)->where('charge_code', $utility->chargeCode)
                ->where('billing_type', BillingType::Fixed)->whereNull('effective_to')
                ->each(fn (ChargeRate $rate) => $rate->forceFill(['effective_to' => $from->subDay()->toDateString()])->save());
        }
    }

    /**
     * Each of the property's metered utilities as billed for each unit: now, and any change waiting for the
     * unit's next bill. One query per property.
     *
     * @param  iterable<Unit>  $units
     * @return array<int, list<array<string, mixed>>> unit id → utilities
     */
    public function unitBillings(Property $property, iterable $units): array
    {
        $today = $this->today((int) $property->account_id);
        $rows = ChargeRate::query()->where('property_id', $property->id)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today->subDay()->toDateString()))
            ->get();
        $charges = $rows->whereNull('unit_id')->whereNull('effective_to')
            ->filter(fn (ChargeRate $r) => $r->isMetered())->sortBy('charge_code')->values();

        $result = [];
        foreach ($units as $unit) {
            $next = $unit->relationLoaded('currentLease') && $unit->currentLease
                ? CarbonImmutable::parse($unit->currentLease->next_billing_date)
                : $today;

            $result[$unit->id] = $charges->map(function (ChargeRate $charge) use ($rows, $unit, $today, $next) {
                $now = $this->pick($rows, $unit, $charge->charge_code, $today) ?? $charge;
                $then = $this->pick($rows, $unit, $charge->charge_code, $next) ?? $charge;
                $state = fn (ChargeRate $r) => [
                    'billing_type' => $r->billing_type->value,
                    'amount' => $r->isMetered() ? null : $r->rate,
                ];

                return [
                    'charge_code' => $charge->charge_code,
                    'label' => $charge->label,
                    'unit_of_measure' => $charge->unit_of_measure,
                    ...$state($now),
                    'metered_rate' => $now->isMetered() ? $now->rate : $charge->rate,
                    'pending' => $state($then) !== $state($now) ? [...$state($then), 'from' => $next->toDateString()] : null,
                ];
            })->all();
        }

        return $result;
    }

    /** Same precedence as applicableTo(), over rows already loaded. */
    private function pick(Collection $rows, Unit $unit, string $code, CarbonImmutable $date): ?ChargeRate
    {
        $day = $date->toDateString();

        return $rows->filter(fn (ChargeRate $r) => $r->charge_code === $code
            && ($r->unit_id === null || (int) $r->unit_id === (int) $unit->id)
            && $r->effective_from->toDateString() <= $day
            && ($r->effective_to === null || $r->effective_to->toDateString() >= $day))
            ->sortBy([fn ($a, $b) => ($a->unit_id === null) <=> ($b->unit_id === null), fn ($a, $b) => $b->effective_from <=> $a->effective_from])
            ->first();
    }

    /** Is the unit already billed this way from $from? */
    private function billedAs(Unit $unit, UnitUtilityData $utility, CarbonImmutable $from): bool
    {
        $rate = $this->resolve($unit, $utility->chargeCode, $from);

        if ($utility->billingType === BillingType::Metered) {
            return $rate === null || $rate->isMetered();
        }

        return $rate !== null && (int) $rate->unit_id === (int) $unit->id && ! $rate->isMetered()
            && Money::toCents($rate->rate) === Money::toCents((string) $utility->amount);
    }

    /**
     * Forget a unit's changes to one charge that haven't started and no bill used: delete such versions
     * (reopening the version each one closed), and reopen a version whose end is still in the future.
     */
    private function undoPending(Unit $unit, string $chargeCode): void
    {
        $today = $this->today((int) $unit->account_id)->toDateString();
        $versions = fn () => ChargeRate::query()->where('unit_id', $unit->id)->where('charge_code', $chargeCode)
            ->orderByDesc('effective_from')->orderByDesc('id');

        foreach ($versions()->whereDate('effective_from', '>=', $today)->get() as $pending) {
            if (InvoiceItem::query()->where('charge_rate_id', $pending->id)->exists()) {
                break;
            }
            $closedDay = $pending->effective_from->subDay()->toDateString();
            $pending->delete();
            $versions()->whereDate('effective_to', $closedDay)->each(fn (ChargeRate $r) => $r->forceFill(['effective_to' => null])->save());
        }

        $latest = $versions()->first();
        if ($latest && $latest->effective_to && $latest->effective_to->toDateString() >= $today) {
            $latest->forceFill(['effective_to' => null])->save();
        }
    }

    private function today(int $accountId): CarbonImmutable
    {
        $timezone = Account::query()->whereKey($accountId)->value('timezone') ?: config('app.timezone');

        return CarbonImmutable::now($timezone)->startOfDay();
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
