<?php

namespace Tests\Feature;

use App\Enums\InvoiceType;
use App\Enums\UnitStatus;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\Property;
use App\Models\Unit;
use App\Services\Meters\MeterReadingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/**
 * Billing day per property and per unit: unit's day → property's day → account's day (5th for Kims).
 * Changing it for a let unit moves the next bill and charges the days in between once.
 */
class BillingDayOverrideTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, RefreshDatabase;

    private Property $property;

    private Unit $house77;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        Mail::fake();
        [$this->property, $this->house77] = $this->asKims(fn () => [Property::firstOrFail(), Unit::where('unit_number', '7-7')->firstOrFail()]);
        $this->actingAsAdmin();
    }

    public function test_property_day_and_unit_day_decide_the_move_in_bill(): void
    {
        $this->putJson("/api/admin/properties/{$this->property->id}", ['name' => $this->property->name, 'billing_day' => 20])->assertAccepted();

        // Follows the property: move in 12 Nov → next bill 20 Nov, rent 12–19 Nov = 8 days = 4,000.
        $a = $this->vacantUnit('7-8');
        $this->moveIn($a, 'peter@example.com', '0722000481')->assertAccepted();
        $this->assertSame('2026-11-20', $this->leaseOf($a)->next_billing_date->toDateString());
        $this->assertSame('4000.00', $this->rentLine($this->leaseOf($a)));

        // Its own day wins: day 1 → next bill 1 Dec, rent 12–30 Nov = 19 days = 9,500.
        $b = $this->vacantUnit('7-9');
        $this->putJson("/api/admin/units/{$b->id}", ['unit_number' => '7-9', 'default_rent' => '15000', 'billing_day' => 1])->assertAccepted();
        $this->moveIn($b, 'mary@example.com', '0722000482')->assertAccepted();
        $this->assertSame('2026-12-01', $this->leaseOf($b)->next_billing_date->toDateString());
        $this->assertSame('9500.00', $this->rentLine($this->leaseOf($b)));

        $this->getJson("/api/admin/properties/{$this->property->id}")
            ->assertJsonPath('data.billing_day', 20)->assertJsonPath('data.effective_billing_day', 20);
        $units = collect($this->getJson("/api/admin/properties/{$this->property->id}/units")->assertOk()->json('data'))->keyBy('unit_number');
        $this->assertSame([null, 20], [$units['7-8']['billing_day'], $units['7-8']['effective_billing_day']]);
        $this->assertSame([1, 1], [$units['7-9']['billing_day'], $units['7-9']['effective_billing_day']]);
    }

    public function test_changing_a_let_units_day_moves_the_bill_and_charges_the_gap_once(): void
    {
        // Jane (7-7) bills on the 5th; switch her unit to the 20th before the 5 Nov run.
        $this->putJson("/api/admin/units/{$this->house77->id}", ['unit_number' => '7-7', 'default_rent' => '15000', 'billing_day' => 20])->assertAccepted();
        $lease = $this->leaseOf($this->house77);
        $this->assertSame('2026-11-20', $lease->next_billing_date->toDateString());
        $this->assertSame('2026-11-04', $lease->last_billed_on->toDateString());

        $this->runBilling('2026-11-05');
        $this->assertSame(0, $this->asKims(fn () => Invoice::count())); // not due on the 5th any more

        $this->travelTo('2026-11-20 06:00');
        $this->runBilling('2026-11-20');
        $bill = $this->asKims(fn () => Invoice::with('items')->sole());
        $this->assertSame(['2026-11-20', '2026-12-19'], [$bill->period_start->toDateString(), $bill->period_end->toDateString()]);
        $gap = $bill->items->firstWhere('charge_code', 'rent_adjustment');
        $this->assertSame('Rent 5 Nov – 19 Nov (15 of 30 days)', $gap->description);
        $this->assertSame('7500.00', $gap->amount);
        $this->assertSame('15000.00', $bill->items->firstWhere('charge_code', 'rent')->amount);
        $this->assertSame('2026-12-20', $lease->fresh()->next_billing_date->toDateString());

        // Void it: the lease goes back to before the gap, so the corrected bill charges it again.
        $this->postJson("/api/admin/invoices/{$bill->id}/void", ['reason' => 'Wrong reading'])->assertAccepted();
        $this->assertSame(['2026-11-20', '2026-11-04'], [$lease->fresh()->next_billing_date->toDateString(), $lease->fresh()->last_billed_on->toDateString()]);
        $this->runBilling('2026-11-20');
        $again = $this->asKims(fn () => Invoice::with('items')->where('status', '!=', 'void')->sole());
        $this->assertSame('7500.00', $again->items->firstWhere('charge_code', 'rent_adjustment')->amount);

        // The next month (with new readings) is a plain month again.
        $this->travelTo('2026-12-20 06:00');
        $this->asKims(function () {
            foreach (Meter::where('unit_id', $this->house77->id)->where('is_active', true)->get() as $meter) {
                app(MeterReadingService::class)->record($meter, now()->subDay()->toImmutable(), (string) ($meter->readings()->max('reading') + 10));
            }
        });
        $this->runBilling('2026-12-20');
        $december = $this->asKims(fn () => Invoice::with('items')->whereDate('period_start', '2026-12-20')->sole());
        $this->assertNull($december->items->firstWhere('charge_code', 'rent_adjustment'));
    }

    public function test_account_day_change_skips_units_and_properties_with_their_own_day(): void
    {
        $other = $this->vacantUnit('7-8');
        $this->moveIn($other, 'peter@example.com', '0722000481')->assertAccepted(); // next bill 5 Dec (account day)
        $this->putJson("/api/admin/units/{$this->house77->id}", ['unit_number' => '7-7', 'default_rent' => '15000', 'billing_day' => 5])->assertAccepted();

        $this->putJson('/api/admin/account/billing', ['billing_day' => 10, 'payment_due_days' => 5, 'invoice_prefix' => 'INV'])->assertAccepted();

        $this->assertSame('2026-11-05', $this->leaseOf($this->house77)->next_billing_date->toDateString()); // its own day
        $this->assertSame('2026-12-10', $this->leaseOf($other)->next_billing_date->toDateString()); // follows the account
    }

    public function test_clearing_and_validation(): void
    {
        $this->putJson("/api/admin/units/{$this->house77->id}", ['unit_number' => '7-7', 'default_rent' => '15000', 'billing_day' => 29])
            ->assertUnprocessable()->assertJsonValidationErrors(['billing_day' => 'Choose a day from 1 to 28 (it must exist in every month).']);
        $this->putJson("/api/admin/properties/{$this->property->id}", ['name' => $this->property->name, 'billing_day' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('billing_day');

        $this->putJson("/api/admin/units/{$this->house77->id}", ['unit_number' => '7-7', 'default_rent' => '15000', 'billing_day' => 20])->assertAccepted();
        // Editing without billing_day keeps it; sending null goes back to the property's / account's day.
        $this->putJson("/api/admin/units/{$this->house77->id}", ['unit_number' => '7-7', 'default_rent' => '16000'])->assertAccepted();
        $this->assertSame(20, $this->house77->fresh()->billing_day);
        $this->putJson("/api/admin/units/{$this->house77->id}", ['unit_number' => '7-7', 'default_rent' => '16000', 'billing_day' => null])->assertAccepted();
        $this->assertNull($this->house77->fresh()->billing_day);
        $this->assertSame('2026-12-05', $this->leaseOf($this->house77)->next_billing_date->toDateString()); // first 5th on/after 20 Nov
    }

    private function vacantUnit(string $number): Unit
    {
        return $this->asKims(function () use ($number) {
            $unit = Unit::factory()->create(['property_id' => $this->property->id, 'unit_number' => $number, 'status' => UnitStatus::Vacant]);
            foreach (['electricity' => '500', 'water' => '40'] as $code => $initial) {
                Meter::create(['unit_id' => $unit->id, 'charge_code' => $code, 'initial_reading' => $initial, 'is_active' => true]);
            }

            return $unit;
        });
    }

    private function moveIn(Unit $unit, string $email, string $phone)
    {
        $meters = $this->asKims(fn () => Meter::where('unit_id', $unit->id)->pluck('id', 'charge_code'));

        return $this->postJson("/api/admin/units/{$unit->id}/move-in", [
            'tenant' => ['first_name' => 'New', 'last_name' => 'Tenant', 'phone' => $phone, 'email' => $email],
            'lease' => ['monthly_rent' => '15000', 'deposit_amount' => '0', 'start_date' => '2026-11-12'],
            'opening_readings' => [$meters['electricity'] => '520', $meters['water'] => '41'],
        ]);
    }

    private function leaseOf(Unit $unit): Lease
    {
        return $this->asKims(fn () => Lease::where('unit_id', $unit->id)->latest('id')->firstOrFail());
    }

    private function rentLine(Lease $lease): string
    {
        return $this->asKims(fn () => Invoice::with('items')->where('lease_id', $lease->id)->where('type', InvoiceType::MoveIn)->sole()
            ->items->firstWhere('charge_code', 'rent')->amount);
    }
}
