<?php

namespace Tests\Feature;

use App\Models\InvoiceItem;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Property;
use App\Services\Meters\MeterReadingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** Readings any time (search, all units), a meter's history, and fixing an unbilled reading. */
class MeterReadingHistoryTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        $this->actingAsAdmin();
    }

    public function test_tasks_for_all_units_with_search(): void
    {
        $this->runBilling(); // next bill 5 Dec: outside the 7-day window
        $this->getJson('/api/admin/readings?days=7')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/admin/readings?days=all')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.bills_on', '2026-12-05');
        $this->getJson('/api/admin/readings?days=all&search=Jane%20Wanjiru')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/readings?days=all&search=7-7')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/readings?days=all&search=nobody')->assertJsonPath('meta.total', 0);
    }

    public function test_history_shows_billed_and_editable_readings_and_a_correction(): void
    {
        $this->runBilling(); // the 4 Nov reading (180) is on INV-2026-000001
        $electricity = $this->asKims(fn () => Meter::where('charge_code', 'electricity')->firstOrFail());

        // A new reading dated the billing day, entered late (on 7 Dec).
        Carbon::setTestNow('2026-12-07 10:00:00');
        $this->postJson('/api/admin/readings', ['reading_date' => '2026-12-05', 'readings' => [['meter_id' => $electricity->id, 'reading' => '280']]])->assertAccepted();

        $history = $this->getJson("/api/admin/meters/{$electricity->id}/readings")->assertOk()
            ->assertJsonPath('data.0.reading_date', '2026-12-05')
            ->assertJsonPath('data.0.editable', true)
            ->assertJsonPath('data.0.recorded_by', 'Kim Landlord')
            ->assertJsonPath('data.1.reading', '180.00')
            ->assertJsonPath('data.1.editable', false)
            ->assertJsonPath('data.1.invoice.invoice_number', 'INV-2026-000001')
            ->json('data');

        // Fix the unbilled one; it can't go below the previous reading; the billed one can't change.
        $this->putJson("/api/admin/meter-readings/{$history[0]['id']}", ['reading' => '260'])->assertAccepted();
        $this->assertSame('260.00', $this->asKims(fn () => MeterReading::find($history[0]['id']))->reading);
        $this->putJson("/api/admin/meter-readings/{$history[0]['id']}", ['reading' => '170'])->assertUnprocessable()->assertJsonValidationErrors('reading');
        $this->putJson("/api/admin/meter-readings/{$history[1]['id']}", ['reading' => '181'])->assertUnprocessable()->assertJsonValidationErrors('reading');

        // The next run bills usage up to that reading.
        $this->asKims(fn () => app(MeterReadingService::class)->record(Meter::where('charge_code', 'water')->firstOrFail(), Carbon::parse('2026-12-05')->toImmutable(), '77'));
        $this->runBilling('2026-12-07');
        $this->assertSame('80.00', $this->asKims(fn () => InvoiceItem::where('charge_code', 'electricity')->latest('id')->value('quantity'))); // 180 → 260
    }

    public function test_caretakers_see_history_only_for_their_properties(): void
    {
        $electricity = $this->asKims(fn () => Meter::where('charge_code', 'electricity')->firstOrFail());
        $this->actingAsAdmin('caretaker@rentflow.test');
        $this->getJson("/api/admin/meters/{$electricity->id}/readings")->assertOk();

        $this->asKims(fn () => Property::query()->update(['caretaker_id' => null]));
        $this->getJson("/api/admin/meters/{$electricity->id}/readings")->assertForbidden();
    }
}
