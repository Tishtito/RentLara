<?php

namespace Tests\Feature;

use App\Enums\LeaseStatus;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\User;
use App\Services\Meters\MeterReadingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** PUT leases/{id}/rent: a new rent from the next bill, with history; old bills never change. */
class LeaseRentChangeTest extends TestCase
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

    public function test_change_rent_keeps_history_and_the_next_bill_uses_it(): void
    {
        $this->runBilling();
        $owner = $this->actingAsAdmin();

        $this->putJson("/api/admin/leases/{$this->lease->id}/rent", ['monthly_rent' => '16000', 'reason' => 'Annual review'])
            ->assertAccepted()->assertJsonPath('message', 'Rent changed.');

        $this->assertSame('16000.00', $this->lease->refresh()->monthly_rent);
        $this->getJson("/api/admin/leases/{$this->lease->id}/rent-changes")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.old_rent', '15000.00')
            ->assertJsonPath('data.0.new_rent', '16000.00')
            ->assertJsonPath('data.0.reason', 'Annual review')
            ->assertJsonPath('data.0.changed_by', $owner->name);
        $this->assertSame('16000.00', AuditLog::where('action', 'lease_rent_change.submitted')->sole()->data['monthlyRent']);

        // The 5 Nov bill keeps 15,000.
        $this->assertSame('15000.00', $this->firstInvoice()->items->firstWhere('charge_code', 'rent')->amount);

        // The 5 Dec bill charges 16,000 (with new readings for the meters).
        Carbon::setTestNow('2026-12-05 06:00:00');
        $this->asKims(function () {
            foreach ($this->lease->unit->activeMeters()->get() as $meter) {
                app(MeterReadingService::class)->record($meter, Carbon::parse('2026-12-04')->toImmutable(), (string) ($meter->charge_code === 'electricity' ? 250 : 80));
            }
        });
        $this->runBilling('2026-12-05');
        $dec = $this->asKims(fn () => Invoice::with('items')->whereDate('period_start', '2026-12-05')->sole());
        $this->assertSame('16000.00', $dec->items->firstWhere('charge_code', 'rent')->amount);
    }

    public function test_guards(): void
    {
        $this->actingAsAdmin();
        $url = "/api/admin/leases/{$this->lease->id}/rent";

        $this->putJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('monthly_rent');
        $this->putJson($url, ['monthly_rent' => '0'])->assertUnprocessable()->assertJsonValidationErrors('monthly_rent');
        $this->putJson($url, ['monthly_rent' => '15000'])->assertUnprocessable()->assertJsonValidationErrors('monthly_rent');

        $this->asKims(fn () => $this->lease->forceFill(['status' => LeaseStatus::Ended, 'end_date' => '2026-11-01'])->save());
        $this->putJson($url, ['monthly_rent' => '16000'])->assertUnprocessable()->assertJsonValidationErrors('lease');
        $this->assertSame(0, AuditLog::where('action', 'lease_rent_change.submitted')->count());
    }

    public function test_permissions(): void
    {
        $url = "/api/admin/leases/{$this->lease->id}/rent";

        // The caretaker can see the tenant but not change the rent.
        $this->actingAsAdmin('caretaker@rentflow.test');
        $this->putJson($url, ['monthly_rent' => '16000'])->assertForbidden();

        // A caretaker of no property can't see it at all.
        Sanctum::actingAs(User::factory()->caretaker()->create(['account_id' => $this->kims->id]));
        $this->getJson("/api/admin/leases/{$this->lease->id}/rent-changes")->assertForbidden();

        $this->assertSame('15000.00', $this->lease->refresh()->monthly_rent);
    }
}
