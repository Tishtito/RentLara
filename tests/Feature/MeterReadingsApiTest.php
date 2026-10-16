<?php

namespace Tests\Feature;

use App\Enums\UnitStatus;
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** Readings screen: tasks agree with what billing:run then bills or skips. Seed: 7-7 bills on 1 Oct. */
class MeterReadingsApiTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
    }

    public function test_tasks_show_captured_and_missing_readings(): void
    {
        $this->actingAsAdmin();

        // Before the 5 Nov bill: both readings captured on 4 Nov.
        $this->getJson('/api/admin/readings')
            ->assertOk()
            ->assertJsonPath('meta', ['total' => 1, 'done' => 1])
            ->assertJsonPath('data.0.unit_number', '7-7')
            ->assertJsonPath('data.0.bills_on', '2026-11-05')
            ->assertJsonPath('data.0.meters.0.charge_code', 'electricity')
            ->assertJsonPath('data.0.meters.0.previous_reading', '100.00')
            ->assertJsonPath('data.0.meters.0.current_reading', '180.00')
            ->assertJsonPath('data.0.meters.0.rate', '30.00');

        // After billing, the next bill (5 Dec) needs new readings, counted from 180 / 75.
        $this->runBilling();
        $this->getJson('/api/admin/readings?days=31')
            ->assertJsonPath('meta', ['total' => 1, 'done' => 0])
            ->assertJsonPath('data.0.bills_on', '2026-12-05')
            ->assertJsonPath('data.0.meters.0.previous_reading', '180.00')
            ->assertJsonPath('data.0.meters.0.current_reading', null);
        $this->getJson('/api/admin/readings?days=7')->assertJsonPath('meta.total', 0);
    }

    public function test_saving_readings_and_correcting_a_typo(): void
    {
        $this->actingAsAdmin();
        $this->runBilling();
        [$electricity, $water] = $this->meters('7-7');

        $this->postJson('/api/admin/readings', ['readings' => [['meter_id' => $electricity->id, 'reading' => '170']]])
            ->assertUnprocessable()->assertJsonValidationErrors('readings.0.reading'); // below 180

        $this->postJson('/api/admin/readings', ['readings' => [
            ['meter_id' => $electricity->id, 'reading' => '260'],
            ['meter_id' => $water->id, 'reading' => '80'],
        ]])->assertAccepted();
        // Same day again: a correction, not a second reading.
        $this->postJson('/api/admin/readings', ['readings' => [['meter_id' => $electricity->id, 'reading' => '250']]])->assertAccepted();

        $this->asKims(fn () => $this->assertSame(['180.00', '250.00'], $electricity->readings()->orderBy('reading_date')->pluck('reading')->all()));
        $this->getJson('/api/admin/readings?days=31')->assertJsonPath('meta.done', 1)->assertJsonPath('data.0.meters.0.current_reading', '250.00');

        // A reading already on a bill can't change.
        $this->postJson('/api/admin/readings', ['reading_date' => '2026-11-04', 'readings' => [['meter_id' => $electricity->id, 'reading' => '181']]])
            ->assertUnprocessable()->assertJsonValidationErrors('readings.0.reading');
    }

    public function test_move_in_opening_reading_starts_the_first_bill(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        $unit = $this->asKims(function () {
            $unit = Unit::factory()->create(['property_id' => Property::firstOrFail()->id, 'unit_number' => '7-8', 'status' => UnitStatus::Vacant]);
            Meter::create(['unit_id' => $unit->id, 'charge_code' => 'electricity', 'initial_reading' => '0', 'is_active' => true]);
            Meter::create(['unit_id' => $unit->id, 'charge_code' => 'water', 'initial_reading' => '0', 'is_active' => true]);

            return $unit;
        });
        [$electricity, $water] = $this->meters('7-8');

        $this->postJson("/api/admin/units/{$unit->id}/move-in", [
            'tenant' => ['first_name' => 'Peter', 'last_name' => 'Mwangi', 'phone' => '0722000481'],
            'lease' => ['monthly_rent' => '10000', 'start_date' => '2026-11-01'], // move-in bill 1–4 Nov, first monthly bill 5 Nov
            'opening_readings' => [$electricity->id => '500', $water->id => '40'],
        ])->assertAccepted();

        // Only the move-in reading exists: the first monthly bill waits for a new one (no charge for usage
        // before move-in). The move-in bill (rent up to the first billing day) is already there.
        $this->getJson('/api/admin/readings')->assertJsonFragment(['unit_number' => '7-8', 'done' => false]);
        $this->runBilling();
        $this->asKims(fn () => $this->assertSame(0, Invoice::where('unit_id', $unit->id)->where('type', 'monthly')->count()));
        $this->asKims(fn () => $this->assertSame(1, Invoice::where('unit_id', $unit->id)->where('type', 'move_in')->count()));

        $this->postJson('/api/admin/readings', ['readings' => [['meter_id' => $electricity->id, 'reading' => '560'], ['meter_id' => $water->id, 'reading' => '43']]])->assertAccepted();
        $this->runBilling();

        $bill = $this->asKims(fn () => Invoice::with('items')->where('unit_id', $unit->id)->where('type', 'monthly')->firstOrFail());
        $line = $bill->items->firstWhere('charge_code', 'electricity');
        $this->assertSame('500.00', $line->previous_reading); // from move-in, not from 0
        $this->assertSame('60.00', $line->quantity);
    }

    public function test_caretakers_read_meters_only_in_their_properties(): void
    {
        $other = $this->asKims(function () {
            $unit = Unit::factory()->create(['property_id' => Property::factory()->create(['account_id' => $this->kims->id])->id]);

            return Meter::create(['unit_id' => $unit->id, 'charge_code' => 'water', 'initial_reading' => '0', 'is_active' => true]);
        });
        [$electricity] = $this->meters('7-7');
        $this->actingAsAdmin('caretaker@rentflow.test');

        $this->getJson('/api/admin/readings')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/admin/readings', ['reading_date' => '2026-11-05', 'readings' => [['meter_id' => $electricity->id, 'reading' => '190']]])->assertAccepted();
        $this->postJson('/api/admin/readings', ['readings' => [['meter_id' => $other->id, 'reading' => '1']]])->assertForbidden();
    }

    public function test_another_landlords_meter_is_rejected(): void
    {
        $this->actingAsAdmin();
        $foreign = Meter::withoutGlobalScopes()->create([
            'account_id' => Unit::factory()->create()->account_id,
            'unit_id' => Unit::factory()->create()->id,
            'charge_code' => 'water',
            'initial_reading' => '0',
        ]);

        $this->postJson('/api/admin/readings', ['readings' => [['meter_id' => $foreign->id, 'reading' => '1']]])
            ->assertUnprocessable()->assertJsonValidationErrors('readings.0.meter_id');
    }

    /** @return array{0: Meter, 1: Meter} electricity, water */
    private function meters(string $unitNumber): array
    {
        return $this->asKims(function () use ($unitNumber) {
            $unit = Unit::where('unit_number', $unitNumber)->firstOrFail();

            return [
                Meter::where('unit_id', $unit->id)->where('charge_code', 'electricity')->where('is_active', true)->firstOrFail(),
                Meter::where('unit_id', $unit->id)->where('charge_code', 'water')->where('is_active', true)->firstOrFail(),
            ];
        });
    }
}
