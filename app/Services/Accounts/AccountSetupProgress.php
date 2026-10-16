<?php

namespace App\Services\Accounts;

use App\Enums\BillingType;
use App\Enums\LeaseStatus;
use App\Models\Account;
use App\Models\ChargeRate;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Services\Properties\MeterService;

/**
 * The getting-started checklist for a new landlord: property → prices → units → meters → billing day →
 * tenants. Each step says whether it's done and where to do it.
 */
class AccountSetupProgress
{
    public function __construct(private MeterService $meters) {}

    /** @return array{complete: bool, steps: list<array<string, mixed>>} */
    public function for(int $accountId): array
    {
        $account = Account::query()->findOrFail($accountId);
        $properties = Property::query()->orderBy('id')->get(['id', 'name']);
        $first = $properties->first();
        $rates = ChargeRate::query()->whereNull('effective_to')->get(['property_id', 'billing_type']);
        $metered = $rates->where('billing_type', BillingType::Metered);
        $units = Unit::query()->count();
        $missingMeters = 0;
        $needsMeters = null;
        foreach ($properties as $property) {
            $missing = $this->meters->setup($property)['missing'];
            $missingMeters += $missing;
            if ($missing > 0 && ! $needsMeters) {
                $needsMeters = $property;
            }
        }
        $leases = Lease::query()->whereIn('status', LeaseStatus::billable())->count();

        $steps = [
            ['key' => 'property', 'title' => 'Add your first property', 'done' => $properties->isNotEmpty(),
                'detail' => $properties->isEmpty() ? 'A building or plot with units.' : "{$properties->count()} propert".($properties->count() === 1 ? 'y' : 'ies'), 'link' => '/admin/properties'],
            ['key' => 'prices', 'title' => 'Set your prices', 'done' => $rates->isNotEmpty(),
                'detail' => $rates->isEmpty() ? 'Electricity and water per unit used, garbage and other fixed charges.' : "{$rates->count()} charge".($rates->count() === 1 ? '' : 's').", {$metered->count()} metered", 'link' => '/admin/settings/billing'],
            ['key' => 'units', 'title' => 'Add units', 'done' => $units > 0,
                'detail' => $units ? "{$units} unit".($units === 1 ? '' : 's') : 'Each house or room you rent out.', 'link' => $first ? "/admin/properties/{$first->id}" : '/admin/properties'],
            ['key' => 'meters', 'title' => 'Fit meters and take their readings', 'done' => $units > 0 && $metered->isNotEmpty() && $missingMeters === 0,
                'detail' => $metered->isEmpty() ? 'Add a metered price (electricity, water) first.' : ($missingMeters ? "{$missingMeters} meter".($missingMeters === 1 ? '' : 's').' still to fit' : 'Every unit has its meters'),
                'link' => ($needsMeters ?? $first) ? '/admin/properties/'.($needsMeters ?? $first)->id.'/meters' : '/admin/properties'],
            ['key' => 'billing_day', 'title' => 'Choose your billing day', 'done' => isset(($account->settings ?? [])['billing_reviewed_at']),
                'detail' => "Bills go out on day {$account->billing_day} of every month.", 'link' => '/admin/settings/billing#cycle'],
            ['key' => 'tenants', 'title' => 'Add your tenants', 'done' => $leases > 0,
                'detail' => $leases ? "{$leases} tenant".($leases === 1 ? '' : 's').' billed by Rentflow' : 'New tenants get a move-in bill; tenants already living there start on the next billing day.',
                'link' => $first ? "/admin/properties/{$first->id}" : '/admin/properties'],
        ];

        return ['complete' => collect($steps)->every(fn ($s) => $s['done']), 'steps' => $steps];
    }
}
