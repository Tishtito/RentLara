<?php

namespace Database\Seeders;

use App\Data\ChangeChargeRateData;
use App\Enums\BillingType;
use App\Enums\TenantStatus;
use App\Enums\UnitStatus;
use App\Models\Account;
use App\Models\Meter;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Leases\LeaseService;
use App\Services\Meters\MeterReadingService;
use App\Services\Rates\ChargeRateService;
use App\Services\Tenants\TenantService;
use App\Support\CurrentAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * The first landlord, set up so House 7-7's first bill matches the paper reference bill:
 * Rent 15,000 · Electricity 100→180 × 30 · Water 70→75 × 170 · Garbage 250 · Arrears 1,000 = 19,500.
 *
 * Kims bills on the 5th. Jane moved in on 2026-09-01, before Rentflow (no move-in bill here; she
 * brings 1,000 of arrears), and is billed up to 4 Nov, so her first Rentflow bill is the monthly bill
 * of 2026-11-05: rent 5 Nov – 4 Dec + garbage + usage up to 4 Nov's readings + arrears = 19,500.
 * `php artisan billing:run --date=2026-11-05` makes it.
 */
class KimsPropertiesSeeder extends Seeder
{
    public const LEASE_START = '2026-09-01';

    public const READING_DATE = '2026-11-04';

    public const FIRST_BILL = '2026-11-05';

    public function run(): void
    {
        $account = Account::create([
            'name' => 'Kims Properties',
            'slug' => 'kims-properties',
            'email' => 'kims@rentflow.test',
            'phone' => '+254700000001',
            'billing_day' => 5,
        ]);

        User::factory()->create([
            'account_id' => $account->id,
            'name' => 'Kim Landlord',
            'email' => 'landlord@rentflow.test',
        ]);

        app(CurrentAccount::class)->actingAs($account, function () {
            $caretaker = User::factory()->caretaker()->create([
                'account_id' => app(CurrentAccount::class)->id(),
                'name' => 'Kims Caretaker',
                'email' => 'caretaker@rentflow.test',
            ]);

            $property = Property::create([
                'name' => 'Kims Apartments',
                'code' => 'KA',
                'town' => 'Kikuyu',
                'county' => 'Kiambu',
                'caretaker_id' => $caretaker->id,
            ]);

            $unit = Unit::create([
                'property_id' => $property->id,
                'unit_number' => '7-7',
                'type' => '1BR',
                'bedrooms' => 1,
                'default_rent' => '15000.00',
                'status' => UnitStatus::Occupied,
            ]);

            foreach ([
                ['electricity', 'Electricity', BillingType::Metered, '30.00', 'kWh'],
                ['water', 'Water', BillingType::Metered, '170.00', 'm³'],
                ['garbage', 'Garbage', BillingType::Fixed, '250.00', null],
            ] as [$code, $label, $type, $rate, $uom]) {
                app(ChargeRateService::class)->change(new ChangeChargeRateData(
                    propertyId: $property->id,
                    chargeCode: $code,
                    rate: $rate,
                    effectiveFrom: CarbonImmutable::parse('2026-01-01'),
                    label: $label,
                    billingType: $type,
                    unitOfMeasure: $uom,
                ));
            }

            $tenant = app(TenantService::class)->create([
                'first_name' => 'Jane',
                'last_name' => 'Wanjiru',
                'email' => 'jane@rentflow.test',
                'national_id' => '12345678',
                'phone' => '+254712345678',
                'password' => 'password',
                'status' => TenantStatus::Active,
            ]);

            app(LeaseService::class)->create([
                'unit_id' => $unit->id,
                'tenant_id' => $tenant->id,
                'start_date' => self::LEASE_START,
                'monthly_rent' => '15000.00',
                'deposit_amount' => '15000.00',
                'opening_balance' => '1000.00', // arrears from before Rentflow
                'last_billed_on' => '2026-11-04', // billed outside Rentflow up to here
                'next_billing_date' => self::FIRST_BILL,
            ]);

            foreach (['electricity' => ['100.00', '180.00'], 'water' => ['70.00', '75.00']] as $code => [$initial, $current]) {
                $meter = Meter::create([
                    'unit_id' => $unit->id,
                    'charge_code' => $code,
                    'initial_reading' => $initial,
                ]);

                app(MeterReadingService::class)->record($meter, CarbonImmutable::parse(self::READING_DATE), $current);
            }
        });
    }
}
