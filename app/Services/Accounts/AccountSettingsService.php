<?php

namespace App\Services\Accounts;

use App\Data\AccountProfileData;
use App\Enums\LeaseStatus;
use App\Models\Account;
use App\Models\Lease;
use App\Services\Billing\BillingCalendar;
use App\Services\Leases\LeaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Landlord account settings. */
class AccountSettingsService
{
    public function __construct(private LeaseService $leases) {}

    /**
     * Billing settings. A new billing day moves each running lease's next bill to the first new billing
     * day on or after it (bills already issued don't change; that bill also charges the days in between),
     * except leases whose unit or property has its own day. Due days and the prefix apply to new bills.
     *
     * @throws ValidationException billing day outside 1–28
     */
    public function updateBilling(int $accountId, int $billingDay, int $paymentDueDays, string $invoicePrefix): Account
    {
        try {
            BillingCalendar::assertValidBillingDay($billingDay);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['billing_day' => 'Choose a day from 1 to 28 (it must exist in every month).']);
        }

        $account = Account::query()->findOrFail($accountId);

        return DB::transaction(function () use ($account, $billingDay, $paymentDueDays, $invoicePrefix) {
            $changed = $account->billing_day !== $billingDay;
            $account->fill([
                'billing_day' => $billingDay,
                'payment_due_days' => $paymentDueDays,
                'invoice_prefix' => $invoicePrefix,
                // The getting-started checklist's "billing day" step.
                'settings' => [...($account->settings ?? []), 'billing_reviewed_at' => now()->toIso8601String()],
            ])->save();

            if ($changed) {
                // Leases of units / properties with their own billing day keep it.
                $this->leases->reschedule(Lease::withoutGlobalScopes()->where('account_id', $account->id)
                    ->whereIn('status', LeaseStatus::billable())->whereNotNull('next_billing_date')->get());
            }

            return $account;
        });
    }

    /** Name, contact details, time zone and billing settings (platform admins). */
    public function updateProfile(AccountProfileData $data): Account
    {
        $account = Account::query()->findOrFail($data->accountId);

        $account->fill([
            'name' => trim($data->name),
            'email' => $data->email ? mb_strtolower(trim($data->email)) : null,
            'phone' => $data->phone,
            'timezone' => $data->timezone,
        ])->save();

        return $this->updateBilling($account->id, $data->billingDay, $data->paymentDueDays, $data->invoicePrefix);
    }
}
