<?php

namespace App\Services\Leases;

use App\Enums\LeaseStatus;
use App\Enums\UnitStatus;
use App\Models\Account;
use App\Models\Lease;
use App\Models\Unit;
use App\Services\Billing\BillingCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lease lifecycle and billing dates. A lease bills on its unit's billing day, else its property's, else the
 * account's (billingDayForUnit): the monthly bill is due on next_billing_date, then it moves to the next
 * month's billing day. Rules: Services\Billing\BillingCalendar.
 */
class LeaseService
{
    /**
     * Create a lease. Its first monthly bill is on the first billing day after move-in (the move-in bill,
     * CreateMoveInInvoice, covers the days before it) unless next_billing_date is given.
     */
    public function create(array $attributes): Lease
    {
        $lease = new Lease($attributes);

        if (! $lease->next_billing_date && $lease->start_date) {
            $lease->next_billing_date = Carbon::instance(BillingCalendar::nextBillingDateAfter($lease->start_date, $this->billingDay($lease)));
        }

        $lease->save();

        return $lease;
    }

    /**
     * Move-out: the lease ends on a date and the unit becomes vacant. The lease's balance stays on the
     * ledger (what the tenant still owes, or their credit); bills are not created for ended leases.
     *
     * @throws ValidationException end date before the lease started, or the lease already ended
     */
    public function end(Lease $lease, CarbonInterface $endDate): Lease
    {
        if ($lease->status === LeaseStatus::Ended) {
            throw ValidationException::withMessages(['lease' => 'This lease has already ended.']);
        }

        if ($endDate->lt($lease->start_date)) {
            throw ValidationException::withMessages(['end_date' => 'The move-out date can\'t be before the move-in date.']);
        }

        return DB::transaction(function () use ($lease, $endDate) {
            $lease->forceFill(['status' => LeaseStatus::Ended, 'end_date' => $endDate->toDateString()])->save();

            $stillLet = Lease::query()
                ->where('unit_id', $lease->unit_id)
                ->whereKeyNot($lease->id)
                ->whereIn('status', LeaseStatus::billable())
                ->exists();

            if (! $stillLet) {
                $lease->unit->forceFill(['status' => UnitStatus::Vacant])->save();
            }

            return $lease;
        });
    }

    /** Leases to bill on a date: active or on notice, with their cycle due. */
    public function dueForBilling(CarbonInterface $date): Collection
    {
        return Lease::query()
            ->whereIn('status', LeaseStatus::billable())
            ->whereDate('next_billing_date', '<=', $date->toDateString())
            ->with('unit')
            ->orderBy('id')
            ->get();
    }

    /**
     * The month the next monthly bill covers for rent and fixed charges, in advance:
     * [next_billing_date, day before the following billing day], e.g. 5 Nov – 4 Dec.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function nextBillingPeriod(Lease $lease): array
    {
        [$start, $end] = BillingCalendar::monthlyPeriod($lease->next_billing_date, $this->billingDay($lease));

        return [CarbonImmutable::instance($start), CarbonImmutable::instance($end)];
    }

    /** After a monthly bill: rent is billed to $periodEnd, the next bill is on the next billing day. */
    public function advanceCycle(Lease $lease, CarbonInterface $periodEnd): void
    {
        $lease->last_billed_on = Carbon::parse($periodEnd)->toDateString();
        $lease->next_billing_date = Carbon::instance(BillingCalendar::nextBillingDateAfter($lease->next_billing_date, $this->billingDay($lease)));
        $lease->save();
    }

    /** The day of the month this lease bills on (1–28): see billingDayForUnit(). */
    public function billingDay(Lease $lease): int
    {
        return $this->billingDayForUnit($lease->unit()->with('property:id,billing_day')->firstOrFail());
    }

    /** The unit's own billing day, else its property's, else the landlord account's. */
    public function billingDayForUnit(Unit $unit): int
    {
        $property = $unit->relationLoaded('property') ? $unit->property : $unit->property()->first(['id', 'billing_day']);

        return $unit->billing_day ?? $property?->billing_day ?? $this->billingDayFor((int) $unit->account_id);
    }

    /**
     * After a billing-day change: each running lease's next bill moves to the first billing day (its own,
     * resolved again) on or after its current one. last_billed_on stays, so the next bill also charges the
     * days in between (InvoiceBuilder's gap line).
     *
     * @param  iterable<Lease>  $leases
     */
    public function reschedule(iterable $leases): void
    {
        foreach ($leases as $lease) {
            if (! in_array($lease->status->value, LeaseStatus::billable(), true) || ! $lease->next_billing_date) {
                continue;
            }

            $next = BillingCalendar::nextBillingDateAfter($lease->next_billing_date->copy()->subDay(), $this->billingDay($lease));
            $lease->forceFill(['next_billing_date' => $next->format('Y-m-d')])->save();
        }
    }

    /** The landlord account's billing day (accounts.billing_day, 1–28). */
    public function billingDayFor(int $accountId): int
    {
        $day = Account::query()->whereKey($accountId)->value('billing_day');

        return (int) ($day ?: BillingCalendar::DEFAULT_BILLING_DAY);
    }
}
