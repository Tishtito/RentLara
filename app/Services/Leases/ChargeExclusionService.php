<?php

namespace App\Services\Leases;

use App\Enums\LeaseStatus;
use App\Events\LeaseChargeExcluded;
use App\Events\LeaseChargeIncluded;
use App\Models\ChargeRate;
use App\Models\Lease;
use App\Models\LeaseChargeExclusion;
use App\Services\Rates\ChargeRateService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Excusing a tenant from one of the property's charges (garbage, security, water…). It follows the lease (the next
 * tenant of the unit pays again) and starts / stops on the tenant's next bill, so bills already made never change
 * (re-bill one to apply it now). Billing reads it through forLeaseBill(), with the same switch-over rules as a unit
 * moving between metered and fixed (ChargeRateService::forBill):
 *   excused from a metered charge: that first bill still has the past month's usage (if read), then nothing;
 *   charged again:                 fixed charges straight away; usage restarts on the bill after (from the reading
 *                                  at the restart), so the excused months are never charged.
 */
class ChargeExclusionService
{
    public function __construct(private ChargeRateService $rates) {}

    /** @throws ValidationException moved out, not a charge of the unit, or already excused */
    public function exclude(Lease $lease, string $chargeCode, ?string $reason = null, ?int $userId = null): LeaseChargeExclusion
    {
        $exclusion = DB::transaction(function () use ($lease, $chargeCode, $reason, $userId) {
            $lease = $this->lockBillable($lease);
            $next = CarbonImmutable::parse($lease->next_billing_date->toDateString());

            if (! $this->rates->allFor($lease->unit, $next)->contains('charge_code', $chargeCode)) {
                throw ValidationException::withMessages(['charge_code' => 'This house has no such charge.']);
            }

            $rows = $this->rowsFor($lease, $chargeCode);
            if ($rows->contains(fn (LeaseChargeExclusion $e) => $e->isActiveOn($next))) {
                throw ValidationException::withMessages(['charge_code' => 'This tenant is already excused from it.']);
            }

            // "Charge again" was waiting for the next bill: just cancel that.
            $ending = $rows->first(fn (LeaseChargeExclusion $e) => $e->ends_on?->toDateString() === $next->subDay()->toDateString());
            if ($ending) {
                $ending->forceFill(['ends_on' => null, 'ended_by' => null, 'reason' => $this->clean($reason) ?? $ending->reason])->save();

                return $ending;
            }

            return $lease->chargeExclusions()->create([
                'account_id' => $lease->account_id,
                'charge_code' => $chargeCode,
                'starts_on' => $next->toDateString(),
                'reason' => $this->clean($reason),
                'created_by' => $userId,
            ]);
        });

        LeaseChargeExcluded::dispatch($exclusion);

        return $exclusion;
    }

    /** @throws ValidationException moved out, or not excused from it */
    public function include(Lease $lease, string $chargeCode, ?int $userId = null): void
    {
        $exclusion = DB::transaction(function () use ($lease, $chargeCode, $userId) {
            $lease = $this->lockBillable($lease);
            $next = CarbonImmutable::parse($lease->next_billing_date->toDateString());

            $active = $this->rowsFor($lease, $chargeCode)->first(fn (LeaseChargeExclusion $e) => $e->isActiveOn($next));
            if (! $active) {
                throw ValidationException::withMessages(['charge_code' => 'This tenant isn\'t excused from it.']);
            }

            if ($active->starts_on->toDateString() >= $next->toDateString()) {
                $active->delete(); // never reached a bill: forget it

                return $active;
            }

            $active->forceFill(['ends_on' => $next->subDay()->toDateString(), 'ended_by' => $userId])->save();

            return $active;
        });

        LeaseChargeIncluded::dispatch($exclusion);
    }

    /**
     * What a lease's monthly bill on $day carries: ChargeRateService::forBill() for its unit, minus the charges the
     * tenant is excused from. `final` = usage lines billed only if read (last usage before going fixed or excused);
     * `excluded` = charges left off this bill.
     *
     * @return array{usage: \Illuminate\Support\Collection<int, ChargeRate>, fixed: \Illuminate\Support\Collection<int, ChargeRate>, final: list<string>, excluded: list<string>}
     */
    public function forLeaseBill(Lease $lease, CarbonInterface $day): array
    {
        $bill = $this->rates->forBill($lease->unit, $day);
        $rows = $lease->chargeExclusions()->get();
        if ($rows->isEmpty()) {
            return $bill + ['excluded' => []];
        }

        $dayBefore = CarbonImmutable::parse($day->toDateString())->subDay();
        $now = $rows->filter(fn (LeaseChargeExclusion $e) => $e->isActiveOn($day))->pluck('charge_code')->unique()->values()->all();
        $before = $rows->filter(fn (LeaseChargeExclusion $e) => $e->isActiveOn($dayBefore))->pluck('charge_code')->unique()->values()->all();
        $final = $bill['final'];

        $usage = $bill['usage']->filter(function (ChargeRate $rate) use ($now, $before, &$final) {
            $code = $rate->charge_code;
            if (in_array($code, $now, true)) {
                if (in_array($code, $before, true)) {
                    return false; // excused for the whole past month
                }
                $final[] = $code; // excused from this bill on: the past month's usage, if read

                return true;
            }

            return ! in_array($code, $before, true); // charged again from this bill: usage starts next bill
        })->values();

        return [
            'usage' => $usage,
            'fixed' => $bill['fixed']->reject(fn (ChargeRate $r) => in_array($r->charge_code, $now, true))->values(),
            'final' => array_values(array_unique($final)),
            'excluded' => $now,
        ];
    }

    /** Where usage restarts after an excused spell that ended before $day (the day after it ended), else null. */
    public function meteredSince(Lease $lease, string $chargeCode, CarbonInterface $day): ?CarbonImmutable
    {
        $end = LeaseChargeExclusion::query()
            ->where('lease_id', $lease->id)
            ->where('charge_code', $chargeCode)
            ->whereNotNull('ends_on')
            ->whereDate('ends_on', '<', $day->toDateString())
            ->max('ends_on');

        return $end ? CarbonImmutable::parse($end)->addDay() : null;
    }

    /**
     * The tenant page: each charge of the house at the next bill and whether the tenant pays it, with history.
     *
     * @return list<array<string, mixed>>
     */
    public function forLease(Lease $lease): array
    {
        $lease->loadMissing('unit');
        $next = CarbonImmutable::parse(($lease->next_billing_date ?? now())->toDateString());
        $rows = $lease->chargeExclusions()->with(['createdBy:id,name', 'endedBy:id,name'])->orderByDesc('starts_on')->orderByDesc('id')->get();

        return $this->rates->allFor($lease->unit, $next)->sortBy('id')->values()->map(function (ChargeRate $rate) use ($rows, $next) {
            $mine = $rows->where('charge_code', $rate->charge_code)->values();
            $active = $mine->first(fn (LeaseChargeExclusion $e) => $e->isActiveOn($next));
            $ending = $mine->first(fn (LeaseChargeExclusion $e) => $e->ends_on?->toDateString() === $next->subDay()->toDateString());

            return [
                'charge_code' => $rate->charge_code,
                'label' => $rate->label,
                'billing_type' => $rate->billing_type->value,
                'rate' => $rate->rate,
                'unit_of_measure' => $rate->unit_of_measure,
                /** Excused on the next bill. */
                'excluded' => $active !== null,
                /** First bill it applies to (in the future = starts on the next bill). */
                'excluded_from' => $active?->starts_on->toDateString(),
                'reason' => $active?->reason,
                /** Charged again from the next bill (the excused spell ended). */
                'charged_again_from' => $active ? null : ($ending ? $next->toDateString() : null),
                'history' => $mine->map(fn (LeaseChargeExclusion $e) => [
                    'id' => $e->id,
                    'starts_on' => $e->starts_on->toDateString(),
                    'ends_on' => $e->ends_on?->toDateString(),
                    'reason' => $e->reason,
                    'created_by' => $e->createdBy?->name,
                    'ended_by' => $e->endedBy?->name,
                ])->all(),
            ];
        })->all();
    }

    private function lockBillable(Lease $lease): Lease
    {
        $lease = Lease::query()->with('unit')->whereKey($lease->getKey())->lockForUpdate()->firstOrFail();
        if (! in_array($lease->status->value, LeaseStatus::billable(), true) || ! $lease->next_billing_date) {
            throw ValidationException::withMessages(['lease' => 'This tenant has moved out.']);
        }

        return $lease;
    }

    /** @return Collection<int, LeaseChargeExclusion> */
    private function rowsFor(Lease $lease, string $chargeCode): Collection
    {
        return $lease->chargeExclusions()->where('charge_code', $chargeCode)->get();
    }

    private function clean(?string $reason): ?string
    {
        return $reason !== null && trim($reason) !== '' ? trim($reason) : null;
    }
}
