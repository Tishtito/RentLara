<?php

namespace Tests\Feature;

use App\Enums\LeaseStatus;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\LeaseChargeExclusion;
use App\Models\Meter;
use App\Models\User;
use App\Services\Meters\MeterReadingService;
use App\Services\Meters\ReadingTasks;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/**
 * Excusing a tenant from a charge (follows the lease, from the next bill). Seed: House 7-7 bills on the 5th:
 * rent 15,000, electricity 100→180 (×30), water 70→75 (×170), garbage 250, arrears 1,000.
 */
class ChargeExclusionTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, RefreshDatabase;

    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        Mail::fake();
        $this->lease = $this->asKims(fn () => Lease::firstOrFail());
    }

    public function test_excused_from_garbage_then_charged_again(): void
    {
        $owner = $this->actingAsAdmin();

        $this->postJson($this->url(), ['charge_code' => 'garbage', 'reason' => 'Uses own collector'])->assertAccepted();
        $this->getJson("/api/admin/leases/{$this->lease->id}/charges")->assertOk()
            ->assertJsonPath('data.2.charge_code', 'garbage')
            ->assertJsonPath('data.2.excluded', true)
            ->assertJsonPath('data.2.excluded_from', '2026-11-05')
            ->assertJsonPath('data.2.reason', 'Uses own collector')
            ->assertJsonPath('data.2.history.0.created_by', $owner->name)
            ->assertJsonPath('data.0.excluded', false);
        $this->assertSame('garbage', AuditLog::where('action', 'lease_charge_exclusion.submitted')->sole()->data['chargeCode']);

        $this->runBilling();
        $nov = $this->bill('2026-11-05');
        $this->assertSame(['rent', 'electricity', 'water'], $this->codes($nov));
        $this->assertSame('19250.00', $nov->total_due);

        // Charge again from the next bill (5 Dec): the spell is kept as history.
        $this->deleteJson($this->url('garbage'))->assertAccepted();
        $this->assertSame('2026-12-04', $this->asKims(fn () => LeaseChargeExclusion::sole()->ends_on->toDateString()));
        $this->getJson("/api/admin/leases/{$this->lease->id}/charges")
            ->assertJsonPath('data.2.excluded', false)->assertJsonPath('data.2.charged_again_from', '2026-12-05');

        $this->read(['electricity' => '200', 'water' => '80'], '2026-12-04');
        Carbon::setTestNow('2026-12-05 06:00:00');
        $this->runBilling('2026-12-05');
        $this->assertSame(['rent', 'electricity', 'water', 'garbage'], $this->codes($this->bill('2026-12-05')));
    }

    public function test_excused_from_water_needs_no_readings_and_restarts_cleanly(): void
    {
        $this->actingAsAdmin();
        $this->runBilling(); // 5 Nov: water 70 → 75 charged

        $this->postJson($this->url(), ['charge_code' => 'water'])->assertAccepted(); // from 5 Dec

        // 5 Dec: no water reading. The past month's usage would be owed, but without a reading it's left off (no skip).
        $this->read(['electricity' => '200'], '2026-12-04');
        $this->assertSame(['electricity' => false, 'water' => true], $this->taskMeters('2026-12-05'));
        Carbon::setTestNow('2026-12-05 06:00:00');
        $this->runBilling('2026-12-05');
        $this->assertSame(['rent', 'electricity', 'garbage'], $this->codes($this->bill('2026-12-05')));

        // 5 Jan: water isn't asked for at all.
        $this->read(['electricity' => '220'], '2027-01-04');
        $this->assertSame(['electricity' => false], $this->taskMeters('2027-01-05'));
        Carbon::setTestNow('2027-01-05 06:00:00');
        $this->runBilling('2027-01-05');
        $this->assertSame(['rent', 'electricity', 'garbage'], $this->codes($this->bill('2027-01-05')));

        // Charged again from 5 Feb: that bill has no water usage; it restarts from the reading taken then.
        $this->deleteJson($this->url('water'))->assertAccepted();
        $this->read(['electricity' => '240', 'water' => '90'], '2027-02-04');
        Carbon::setTestNow('2027-02-05 06:00:00');
        $this->runBilling('2027-02-05');
        $this->assertSame(['rent', 'electricity', 'garbage'], $this->codes($this->bill('2027-02-05')));

        $this->read(['electricity' => '260', 'water' => '95'], '2027-03-04');
        Carbon::setTestNow('2027-03-05 06:00:00');
        $this->runBilling('2027-03-05');
        $water = $this->bill('2027-03-05')->items->firstWhere('charge_code', 'water');
        $this->assertSame(['90.00', '95.00', '850.00'], [$water->previous_reading, $water->current_reading, $water->amount]);
    }

    public function test_guards_and_a_pending_exclusion_is_simply_removed(): void
    {
        $this->actingAsAdmin();

        $this->postJson($this->url(), [])->assertUnprocessable()->assertJsonValidationErrors('charge_code');
        $this->postJson($this->url(), ['charge_code' => 'parking'])->assertUnprocessable()->assertJsonValidationErrors('charge_code');
        $this->deleteJson($this->url('garbage'))->assertUnprocessable()->assertJsonValidationErrors('charge_code');

        $this->postJson($this->url(), ['charge_code' => 'garbage'])->assertAccepted();
        $this->postJson($this->url(), ['charge_code' => 'garbage'])->assertUnprocessable()->assertJsonValidationErrors('charge_code');

        // Never reached a bill: removing it forgets it.
        $this->deleteJson($this->url('garbage'))->assertAccepted();
        $this->assertSame(0, $this->asKims(fn () => LeaseChargeExclusion::count()));

        $this->asKims(fn () => $this->lease->forceFill(['status' => LeaseStatus::Ended, 'end_date' => '2026-11-04'])->save());
        $this->postJson($this->url(), ['charge_code' => 'garbage'])->assertUnprocessable()->assertJsonValidationErrors('lease');
    }

    public function test_it_follows_the_tenant_not_the_house(): void
    {
        $this->actingAsAdmin();
        $this->postJson($this->url(), ['charge_code' => 'garbage'])->assertAccepted();
        $this->postJson("/api/admin/leases/{$this->lease->id}/end", ['end_date' => '2026-11-04'])->assertAccepted();

        $meters = $this->asKims(fn () => Meter::where('unit_id', $this->lease->unit_id)->pluck('id', 'charge_code'));
        $this->postJson("/api/admin/units/{$this->lease->unit_id}/move-in", [
            'tenant' => ['first_name' => 'Peter', 'last_name' => 'Mwangi', 'phone' => '0722000481'],
            'lease' => ['monthly_rent' => '15000', 'deposit_amount' => '0', 'start_date' => '2026-11-05'],
            'opening_readings' => [$meters['electricity'] => '180', $meters['water'] => '75'],
        ])->assertAccepted();
        $peter = $this->asKims(fn () => Lease::where('unit_id', $this->lease->unit_id)->whereKeyNot($this->lease->id)->sole());

        $this->getJson("/api/admin/leases/{$peter->id}/charges")->assertJsonPath('data.2.charge_code', 'garbage')->assertJsonPath('data.2.excluded', false);
    }

    public function test_permissions(): void
    {
        $this->actingAsAdmin('caretaker@rentflow.test');
        $this->getJson("/api/admin/leases/{$this->lease->id}/charges")->assertOk();
        $this->postJson($this->url(), ['charge_code' => 'garbage'])->assertForbidden();
        $this->deleteJson($this->url('garbage'))->assertForbidden();

        Sanctum::actingAs(User::factory()->caretaker()->create(['account_id' => $this->kims->id]));
        $this->getJson("/api/admin/leases/{$this->lease->id}/charges")->assertForbidden();

        $this->assertSame(0, $this->asKims(fn () => LeaseChargeExclusion::count()));
    }

    private function url(?string $code = null): string
    {
        return "/api/admin/leases/{$this->lease->id}/charge-exclusions".($code ? "/{$code}" : '');
    }

    private function bill(string $periodStart): Invoice
    {
        return $this->asKims(fn () => Invoice::with('items')->where('lease_id', $this->lease->id)->whereDate('period_start', $periodStart)->sole());
    }

    /** @return list<string> */
    private function codes(Invoice $invoice): array
    {
        return $invoice->items->sortBy('sort_order')->pluck('charge_code')->values()->all();
    }

    /** @param array<string, string> $values */
    private function read(array $values, string $date): void
    {
        $this->asKims(function () use ($values, $date) {
            foreach ($values as $code => $value) {
                $meter = Meter::where('unit_id', $this->lease->unit_id)->where('charge_code', $code)->firstOrFail();
                app(MeterReadingService::class)->record($meter, CarbonImmutable::parse($date), $value);
            }
        });
    }

    /** Meters the readings screen lists for 7-7 at its next bill: code → optional. */
    private function taskMeters(string $today): array
    {
        return $this->asKims(function () use ($today) {
            $task = app(ReadingTasks::class)->due(User::where('email', 'landlord@rentflow.test')->firstOrFail(), null, 7, CarbonImmutable::parse($today))
                ->firstWhere('lease_id', $this->lease->id);

            return collect($task['meters'] ?? [])->mapWithKeys(fn ($m) => [$m['charge_code'] => $m['optional']])->sortKeys()->all();
        });
    }
}
