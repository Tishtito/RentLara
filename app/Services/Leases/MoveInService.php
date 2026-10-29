<?php

namespace App\Services\Leases;

use App\Data\MoveInData;
use App\Enums\LeaseStatus;
use App\Enums\TenantStatus;
use App\Enums\UnitStatus;
use App\Models\Account;
use App\Models\Lease;
use App\Models\Unit;
use App\Services\Billing\BillingCalendar;
use App\Services\Billing\CreateMoveInInvoice;
use App\Services\Meters\MeterReadingService;
use App\Services\Rates\ChargeRateService;
use App\Services\Tenants\TenantInvitationService;
use App\Services\Tenants\TenantService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Move-in in one step: the tenant, their lease, the unit becomes occupied, every active meter billed by
 * usage gets its opening reading on the move-in date (so the first monthly bill counts usage from move-in;
 * utilities billed a fixed amount need none, but a reading sent for one is still recorded), the
 * move-in bill (deposit + rent up to the first billing day, CreateMoveInInvoice) is issued, and the
 * tenant is invited to the portal by email when they have one.
 */
class MoveInService
{
    public function __construct(
        private TenantService $tenants,
        private LeaseService $leases,
        private MeterReadingService $readings,
        private TenantInvitationService $invitations,
        private CreateMoveInInvoice $moveInInvoice,
        private ChargeRateService $rates,
    ) {}

    /** @throws ValidationException unit not available, missing/invalid opening reading */
    public function moveIn(MoveInData $data): Lease
    {
        return DB::transaction(function () use ($data) {
            $unit = Unit::query()->with('activeMeters')->lockForUpdate()->findOrFail($data->unitId);

            if (! in_array($unit->status, [UnitStatus::Vacant, UnitStatus::Reserved], true)) {
                throw ValidationException::withMessages(['unit' => "Unit {$unit->unit_number} is not vacant."]);
            }

            // Already living there: Rentflow starts today, so the opening readings are today's.
            $readingsDate = $data->existing
                ? CarbonImmutable::now(Account::query()->whereKey($unit->account_id)->value('timezone') ?: config('app.timezone'))->startOfDay()
                : $data->startDate;

            foreach ($unit->activeMeters as $meter) {
                $rate = $this->rates->resolve($unit, $meter->charge_code, $readingsDate);
                $metered = ! $rate || $rate->isMetered();
                if ($metered && (! isset($data->openingReadings[$meter->id]) || $data->openingReadings[$meter->id] === '')) {
                    throw ValidationException::withMessages(["opening_readings.{$meter->id}" => "Enter the {$meter->charge_code} reading on the move-in date."]);
                }
            }

            $tenant = $this->tenants->create([
                ...$data->tenant,
                'status' => TenantStatus::Invited,
            ]);

            $attributes = [
                'unit_id' => $unit->id,
                'tenant_id' => $tenant->id,
                'start_date' => $data->startDate->toDateString(),
                'monthly_rent' => $data->monthlyRent,
                'deposit_amount' => $data->depositAmount,
                'opening_balance' => $data->openingBalance,
                'status' => LeaseStatus::Active,
            ];

            // Already living there: Rentflow starts today. Rent up to the first bill is settled outside
            // Rentflow (what they owe goes in opening_balance) and the deposit is already held.
            if ($data->existing) {
                $firstBill = $data->firstBillDate ?? CarbonImmutable::instance(BillingCalendar::nextBillingDateAfter($readingsDate, $this->leases->billingDayForUnit($unit)));
                $attributes += [
                    'onboarded_on' => $readingsDate->toDateString(),
                    'next_billing_date' => $firstBill->toDateString(),
                    'last_billed_on' => $firstBill->subDay()->toDateString(),
                    'deposit_invoiced_at' => now(),
                ];
            }

            $lease = $this->leases->create($attributes);

            $unit->forceFill(['status' => UnitStatus::Occupied])->save();

            foreach ($unit->activeMeters as $meter) {
                if (! isset($data->openingReadings[$meter->id]) || $data->openingReadings[$meter->id] === '') {
                    continue; // a fixed-amount utility
                }
                try {
                    $this->readings->record($meter, $readingsDate, $data->openingReadings[$meter->id], $data->createdBy);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(["opening_readings.{$meter->id}" => $e->errors()['reading'] ?? $e->getMessage()]);
                }
            }

            // New tenants: deposit + rent up to the first billing day (e.g. 20 Oct → 4 Nov = 16 of 30 days).
            if (! $data->existing) {
                ($this->moveInInvoice)($lease);
            }

            if ($tenant->email) {
                $this->invitations->send($tenant, $data->createdBy);
            }

            return $lease;
        });
    }
}
