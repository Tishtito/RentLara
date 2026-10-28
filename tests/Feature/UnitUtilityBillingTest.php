<?php

namespace Tests\Feature;

use App\Models\ChargeRate;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Meters\MeterReadingService;
use App\Services\Meters\ReadingTasks;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/**
 * Per unit, each metered utility is billed by meter or as a fixed monthly amount (set on the unit form).
 * Seed: House 7-7 (electricity 100→180, water 70→75 read 4 Nov), first bill 5 Nov.
 */
class UnitUtilityBillingTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, RefreshDatabase;

    private Property $property;

    private Unit $house;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        Mail::fake();
        $this->actingAsAdmin();
        [$this->property, $this->house] = $this->asKims(fn () => [Property::firstOrFail(), Unit::where('unit_number', '7-7')->firstOrFail()]);
    }

    public function test_a_new_unit_can_pay_a_fixed_amount_for_water_and_fix_a_typo_the_same_day(): void
    {
        $this->postJson("/api/admin/properties/{$this->property->id}/units", [
            'unit_number' => '7-8', 'default_rent' => '12000',
            'utilities' => [
                ['charge_code' => 'water', 'billing_type' => 'fixed', 'amount' => '500'],
                ['charge_code' => 'electricity', 'billing_type' => 'metered'],
            ],
        ])->assertAccepted();
        $unit = $this->asKims(fn () => Unit::where('unit_number', '7-8')->firstOrFail());

        $this->getJson("/api/admin/properties/{$this->property->id}/units")
            ->assertOk()
            ->assertJsonPath('data.1.unit_number', '7-8')
            ->assertJsonPath('data.1.utilities.0.charge_code', 'electricity')
            ->assertJsonPath('data.1.utilities.0.billing_type', 'metered')
            ->assertJsonPath('data.1.utilities.0.metered_rate', '30.00')
            ->assertJsonPath('data.1.utilities.1.charge_code', 'water')
            ->assertJsonPath('data.1.utilities.1.billing_type', 'fixed')
            ->assertJsonPath('data.1.utilities.1.amount', '500.00')
            ->assertJsonPath('data.1.utilities.1.pending', null)
            ->assertJsonPath('data.0.utilities.1.billing_type', 'metered'); // 7-7 unchanged

        // Typo: 600, not 500. The unused version is replaced, not stacked.
        $this->putJson("/api/admin/units/{$unit->id}", [
            'unit_number' => '7-8', 'default_rent' => '12000',
            'utilities' => [['charge_code' => 'water', 'billing_type' => 'fixed', 'amount' => '600']],
        ])->assertAccepted();
        $this->assertSame(['600.00'], $this->unitRates($unit)->pluck('rate')->all());

        // Back to the meter: no unit price left, the property's water price applies.
        $this->putJson("/api/admin/units/{$unit->id}", [
            'unit_number' => '7-8', 'default_rent' => '12000',
            'utilities' => [['charge_code' => 'water', 'billing_type' => 'metered']],
        ])->assertAccepted();
        $this->assertCount(0, $this->unitRates($unit));
    }

    public function test_only_metered_utilities_need_a_meter_and_an_opening_reading(): void
    {
        $this->postJson("/api/admin/properties/{$this->property->id}/units", [
            'unit_number' => '7-8', 'default_rent' => '15000',
            'utilities' => [['charge_code' => 'water', 'billing_type' => 'fixed', 'amount' => '500']],
        ])->assertAccepted();
        $unit = $this->asKims(fn () => Unit::where('unit_number', '7-8')->firstOrFail());
        $electricity = $this->asKims(fn () => Meter::create(['unit_id' => $unit->id, 'charge_code' => 'electricity', 'initial_reading' => '500', 'is_active' => true]));

        $this->getJson("/api/admin/properties/{$this->property->id}/meter-setup")
            ->assertOk()
            ->assertJsonPath('data.units.1.fixed', ['water'])
            ->assertJsonPath('data.missing', 0);

        $moveIn = fn (array $readings) => $this->postJson("/api/admin/units/{$unit->id}/move-in", [
            'tenant' => ['first_name' => 'Peter', 'last_name' => 'Mwangi', 'phone' => '0722000481', 'email' => 'peter@example.com'],
            'lease' => ['monthly_rent' => '15000', 'deposit_amount' => '15000', 'start_date' => '2026-11-05'],
            'opening_readings' => $readings,
        ]);
        $moveIn([])->assertUnprocessable()->assertJsonValidationErrors("opening_readings.{$electricity->id}");
        $moveIn([$electricity->id => '520'])->assertAccepted();

        // First monthly bill (5 Dec): electricity by meter, water the fixed 500.
        $this->record(['electricity' => '540'], '2026-12-04', $unit);
        Carbon::setTestNow('2026-12-05 06:00:00');
        $this->runBilling('2026-12-05');

        $bill = $this->asKims(fn () => Invoice::with('items')->where('unit_id', $unit->id)->where('type', 'monthly')->sole());
        $this->assertSame([
            ['rent', '15000.00'],
            ['electricity', '600.00'], // 520 → 540 = 20 × 30
            ['water', '500.00'],
            ['garbage', '250.00'],
        ], $bill->items->map(fn ($i) => [$i->charge_code, $i->amount])->all());
    }

    public function test_switching_an_occupied_unit_to_fixed_bills_the_last_usage_then_the_fixed_amount(): void
    {
        $this->setWater($this->house, 'fixed', '500')->assertAccepted(); // 7-7's next bill is today, 5 Nov

        $this->runBilling();
        $this->assertSame([
            ['rent', '15000.00'],
            ['electricity', '2400.00'],
            ['water', '850.00'],   // last usage, 70 → 75
            ['water', '500.00'],   // first fixed month, 5 Nov – 4 Dec
            ['garbage', '250.00'],
        ], $this->billLines($this->firstInvoice()));

        // Next month: no water reading needed.
        $this->record(['electricity' => '190'], '2026-12-04');
        Carbon::setTestNow('2026-12-05 06:00:00');
        $this->assertSame(['electricity'], $this->readingTaskCodes('2026-12-05'));
        $this->runBilling('2026-12-05');

        $this->assertSame([
            ['rent', '15000.00'],
            ['electricity', '300.00'],
            ['water', '500.00'],
            ['garbage', '250.00'],
        ], $this->billLines($this->latestInvoice()));
    }

    public function test_switching_back_to_the_meter_starts_usage_from_the_next_bill(): void
    {
        $this->setWater($this->house, 'fixed', '500');
        $this->runBilling(); // 5 Nov: last water usage + first fixed 500

        // Back to the meter from the next bill (5 Dec): shown as pending until then.
        $this->setWater($this->house, 'metered')->assertAccepted();
        $this->getJson("/api/admin/properties/{$this->property->id}/units")
            ->assertJsonPath('data.0.utilities.1.billing_type', 'fixed')
            ->assertJsonPath('data.0.utilities.1.pending.billing_type', 'metered')
            ->assertJsonPath('data.0.utilities.1.pending.from', '2026-12-05');

        $this->record(['electricity' => '190', 'water' => '78'], '2026-12-04');
        Carbon::setTestNow('2026-12-05 06:00:00');
        $this->assertSame(['electricity', 'water'], $this->readingTaskCodes('2026-12-05')); // water: the starting reading
        $this->runBilling('2026-12-05');

        // 5 Dec: the fixed 500 on 5 Nov already covered Nov's water, so none on this bill.
        $this->assertSame([
            ['rent', '15000.00'],
            ['electricity', '300.00'],
            ['garbage', '250.00'],
        ], $this->billLines($this->latestInvoice()));

        // 5 Jan: water usage from the 4 Dec reading (78), not from the last billed one (75).
        $this->record(['electricity' => '200', 'water' => '80'], '2027-01-04');
        Carbon::setTestNow('2027-01-05 06:00:00');
        $this->runBilling('2027-01-05');

        $this->assertSame([
            ['rent', '15000.00'],
            ['electricity', '300.00'],
            ['water', '340.00'], // 78 → 80 = 2 × 170
            ['garbage', '250.00'],
        ], $this->billLines($this->latestInvoice()));
    }

    public function test_a_change_waiting_for_the_next_bill_can_be_undone(): void
    {
        $this->runBilling(); // next bill 5 Dec
        $this->setWater($this->house, 'fixed', '500');
        $this->assertSame(['2026-12-05'], $this->unitRates($this->house)->map(fn ($r) => $r->effective_from->toDateString())->all());

        $this->setWater($this->house, 'metered');
        $this->assertCount(0, $this->unitRates($this->house));
    }

    public function test_only_the_propertys_metered_charges_can_be_set_and_fixed_needs_an_amount(): void
    {
        $put = fn (array $utility) => $this->putJson("/api/admin/units/{$this->house->id}", [
            'unit_number' => '7-7', 'default_rent' => '15000', 'utilities' => [$utility],
        ]);

        $put(['charge_code' => 'garbage', 'billing_type' => 'fixed', 'amount' => '100'])
            ->assertUnprocessable()->assertJsonValidationErrors('utilities.0.charge_code');
        $put(['charge_code' => 'gas', 'billing_type' => 'metered'])
            ->assertUnprocessable()->assertJsonValidationErrors('utilities.0.charge_code');
        $put(['charge_code' => 'water', 'billing_type' => 'fixed'])
            ->assertUnprocessable()->assertJsonValidationErrors('utilities.0.amount');
        $this->assertCount(0, $this->unitRates($this->house));
    }

    private function setWater(Unit $unit, string $type, ?string $amount = null)
    {
        return $this->putJson("/api/admin/units/{$unit->id}", [
            'unit_number' => $unit->unit_number, 'default_rent' => '15000',
            'utilities' => [array_filter(['charge_code' => 'water', 'billing_type' => $type, 'amount' => $amount])],
        ]);
    }

    /** @param array<string, string> $values charge code → reading */
    private function record(array $values, string $date, ?Unit $unit = null): void
    {
        $this->asKims(function () use ($values, $date, $unit) {
            foreach ($values as $code => $value) {
                $meter = Meter::where('unit_id', ($unit ?? $this->house)->id)->where('charge_code', $code)->firstOrFail();
                app(MeterReadingService::class)->record($meter, CarbonImmutable::parse($date), $value);
            }
        });
    }

    private function unitRates(Unit $unit)
    {
        return $this->asKims(fn () => ChargeRate::where('unit_id', $unit->id)->orderBy('id')->get());
    }

    private function latestInvoice(): Invoice
    {
        return $this->asKims(fn () => Invoice::with('items')->where('unit_id', $this->house->id)->latest('id')->firstOrFail());
    }

    private function billLines(Invoice $invoice): array
    {
        return $invoice->items->sortBy('sort_order')->map(fn ($i) => [$i->charge_code, $i->amount])->values()->all();
    }

    /** Meters the readings screen asks for at 7-7's next bill. */
    private function readingTaskCodes(string $today): array
    {
        return $this->asKims(function () use ($today) {
            $user = User::where('email', 'landlord@rentflow.test')->firstOrFail();
            $task = app(ReadingTasks::class)->due($user, null, 7, CarbonImmutable::parse($today))
                ->firstWhere('lease_id', Lease::where('unit_id', $this->house->id)->value('id'));

            return collect($task['meters'] ?? [])->pluck('charge_code')->sort()->values()->all();
        });
    }
}
