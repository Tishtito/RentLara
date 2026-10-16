<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** Dashboard numbers on the Kims seed (reference bill 19,500 = 18,500 + 1,000 arrears). */
class DashboardApiTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims(); // today = 2026-11-05, the billing day
    }

    public function test_before_and_after_the_billing_run(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.cycle.billed', '0.00')
            ->assertJsonPath('data.arrears', ['total' => '1000.00', 'leases' => 1]) // opening balance
            ->assertJsonPath('data.occupancy', ['units' => 1, 'occupied' => 1, 'vacant' => 0, 'properties' => 1])
            ->assertJsonPath('data.upcoming.0.unit_number', '7-7')
            ->assertJsonPath('data.upcoming.0.bills_on', '2026-11-05')
            ->assertJsonPath('data.upcoming.0.readings', 'ready');

        $this->runBilling();
        $lease = $this->asKims(fn () => Lease::firstOrFail());
        $this->postJson('/api/admin/payments', ['lease_id' => $lease->id, 'amount' => '5000', 'method' => 'cash', 'paid_at' => '2026-11-05 05:00:00'])->assertAccepted();

        $this->getJson('/api/admin/dashboard')
            ->assertJsonPath('data.cycle.billed', '18500.00')
            ->assertJsonPath('data.cycle.bills_count', 1)
            ->assertJsonPath('data.cycle.collected', '5000.00')
            ->assertJsonPath('data.cycle.collection_rate', 27)
            ->assertJsonPath('data.arrears.total', '14500.00')
            ->assertJsonPath('data.by_property.0.billed', '18500.00')
            ->assertJsonPath('data.upcoming', []); // next bill is 5 Dec
    }

    public function test_attention_overdue_bills_and_missing_readings(): void
    {
        $this->actingAsAdmin();
        $this->runBilling(); // due 10 Nov

        $this->travelTo('2026-11-14 09:00:00');
        $this->getJson('/api/admin/dashboard')
            ->assertJsonPath('data.attention.overdue_bills', ['count' => 1, 'amount' => '19500.00'])
            ->assertJsonPath('data.attention.missing_readings.count', 0);

        // Two days before the 5 Dec bill, with no new readings yet.
        $this->travelTo('2026-12-03 09:00:00');
        $this->getJson('/api/admin/dashboard')
            ->assertJsonPath('data.attention.missing_readings', ['count' => 1, 'units' => ['7-7']])
            ->assertJsonPath('data.upcoming.0.readings', 'missing');
    }

    public function test_caretakers_see_their_properties_only(): void
    {
        $this->asKims(fn () => Property::factory()->create(['account_id' => $this->kims->id, 'name' => 'Not Mine']));
        $this->actingAsAdmin('caretaker@rentflow.test');

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.occupancy.properties', 1)
            ->assertJsonCount(1, 'data.by_property');
    }
}
