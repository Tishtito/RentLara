<?php

namespace Tests\Feature;

use App\Enums\UnitStatus;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** A landlord joining Rentflow adds tenants who already live there: no move-in bill. */
class ExistingTenantOnboardingTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, RefreshDatabase;

    private Unit $unit;

    /** @var array<string, Meter> */
    private array $meters;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        Carbon::setTestNow('2026-10-16 10:00:00'); // onboarding day
        Mail::fake();
        [$this->unit, $this->meters] = $this->asKims(function () {
            $unit = Unit::factory()->create(['property_id' => Property::firstOrFail()->id, 'unit_number' => '8-1', 'status' => UnitStatus::Vacant]);
            $meters = [];
            foreach (['electricity' => '1500', 'water' => '30'] as $code => $initial) {
                $meters[$code] = Meter::create(['unit_id' => $unit->id, 'charge_code' => $code, 'initial_reading' => $initial, 'is_active' => true]);
            }

            return [$unit, $meters];
        });
        $this->actingAsAdmin();
    }

    public function test_existing_tenant_gets_no_move_in_bill_and_is_billed_from_todays_readings(): void
    {
        $this->addExisting()->assertAccepted();

        $lease = $this->asKims(fn () => Lease::where('unit_id', $this->unit->id)->firstOrFail());
        $this->assertSame(0, $this->asKims(fn () => Invoice::where('lease_id', $lease->id)->count()));
        $this->assertSame(['2025-03-01', '2026-10-16', '2026-11-05', '2026-11-04'], [
            $lease->start_date->toDateString(), $lease->onboarded_on->toDateString(),
            $lease->next_billing_date->toDateString(), $lease->last_billed_on->toDateString(),
        ]);
        $this->assertNotNull($lease->deposit_invoiced_at); // held already, never billed
        $this->assertSame('2026-10-16', $this->asKims(fn () => $this->meters['electricity']->readings()->sole()->reading_date->toDateString()));

        // The 5 Nov bill counts usage from today's readings, plus the arrears they brought.
        Carbon::setTestNow('2026-11-05 06:00:00');
        $this->postJson('/api/admin/readings', ['readings' => [
            ['meter_id' => $this->meters['electricity']->id, 'reading' => '1600'],
            ['meter_id' => $this->meters['water']->id, 'reading' => '34'],
        ]])->assertAccepted();
        $this->runBilling('2026-11-05');

        $bill = $this->asKims(fn () => Invoice::with('items')->where('lease_id', $lease->id)->sole());
        $this->assertSame('monthly', $bill->type->value);
        $this->assertSame([
            'rent' => '12000.00',
            'electricity' => '2400.00', // 1520 (onboarding) → 1600 = 80 × 30
            'water' => '680.00',        // 30 → 34 = 4 × 170
            'garbage' => '250.00',
        ], $bill->items->mapWithKeys(fn ($i) => [$i->charge_code => $i->amount])->all());
        $this->assertSame('2000.00', $bill->balance_brought_forward);
        $this->assertSame('17330.00', $bill->total_due);
    }

    public function test_first_bill_date_can_be_chosen_and_is_only_for_existing_tenants(): void
    {
        $this->addExisting(['first_bill_date' => '2026-12-05'])->assertAccepted();
        $this->assertSame('2026-12-05', $this->asKims(fn () => Lease::where('unit_id', $this->unit->id)->firstOrFail())->next_billing_date->toDateString());
    }

    public function test_first_bill_date_is_refused_for_new_tenants(): void
    {
        $this->addExisting(['existing' => false, 'first_bill_date' => '2026-12-05'])->assertUnprocessable()->assertJsonValidationErrors('lease.first_bill_date');
    }

    private function addExisting(array $lease = [])
    {
        return $this->postJson("/api/admin/units/{$this->unit->id}/move-in", [
            'tenant' => ['first_name' => 'Mary', 'last_name' => 'Achieng', 'phone' => '0711000222'],
            'lease' => $lease + ['existing' => true, 'monthly_rent' => '12000', 'deposit_amount' => '12000', 'start_date' => '2025-03-01', 'opening_balance' => '2000'],
            'opening_readings' => [$this->meters['electricity']->id => '1520', $this->meters['water']->id => '30'],
        ]);
    }
}
