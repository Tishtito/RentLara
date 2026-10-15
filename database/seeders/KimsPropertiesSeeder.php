<?php

namespace Database\Seeders;

use App\Enums\BillingType;
use App\Enums\TenantStatus;
use App\Enums\UnitStatus;
use App\Models\Account;
use App\Models\ChargeRate;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\CurrentAccount;
use Illuminate\Database\Seeder;

/**
 * The first landlord, set up so House 7-7's first bill matches the paper reference bill:
 * Rent 15,000 · Electricity 100→180 × 30 · Water 70→75 × 170 · Garbage 250 · Arrears 1,000 = 19,500.
 *
 * Lease starts 2026-09-01, so with a 29-day cycle the first bill covers 2026-09-01 → 2026-09-29.
 */
class KimsPropertiesSeeder extends Seeder
{
    public const LEASE_START = '2026-09-01';

    public const READING_DATE = '2026-09-29';

    public function run(): void
    {
        $account = Account::create([
            'name' => 'Kims Properties',
            'slug' => 'kims-properties',
            'email' => 'kims@rentflow.test',
            'phone' => '+254700000001',
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
                ChargeRate::create([
                    'property_id' => $property->id,
                    'charge_code' => $code,
                    'label' => $label,
                    'billing_type' => $type,
                    'rate' => $rate,
                    'unit_of_measure' => $uom,
                    'effective_from' => '2026-01-01',
                ]);
            }

            $tenant = Tenant::create([
                'first_name' => 'Jane',
                'last_name' => 'Wanjiru',
                'email' => 'jane@rentflow.test',
                'national_id' => '12345678',
                'phone' => '+254712345678',
                'password' => 'password',
                'status' => TenantStatus::Active,
            ]);

            Lease::create([
                'unit_id' => $unit->id,
                'tenant_id' => $tenant->id,
                'start_date' => self::LEASE_START,
                'monthly_rent' => '15000.00',
                'deposit_amount' => '15000.00',
                'opening_balance' => '1000.00', // arrears from before Rentflow
            ]);

            foreach (['electricity' => ['100.00', '180.00'], 'water' => ['70.00', '75.00']] as $code => [$initial, $current]) {
                $meter = Meter::create([
                    'unit_id' => $unit->id,
                    'charge_code' => $code,
                    'initial_reading' => $initial,
                ]);

                MeterReading::create([
                    'meter_id' => $meter->id,
                    'reading_date' => self::READING_DATE,
                    'reading' => $current,
                ]);
            }
        });
    }
}
