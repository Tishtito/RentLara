<?php

namespace Tests\Feature;

use App\Enums\UnitStatus;
use App\Models\Meter;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** Meter setup: every unit × metered charge on one screen, many meters fitted at once. */
class MeterSetupApiTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, RefreshDatabase;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        $this->property = $this->asKims(function () {
            $property = Property::firstOrFail();
            foreach (['8-1', '8-2'] as $number) {
                Unit::factory()->create(['property_id' => $property->id, 'unit_number' => $number, 'status' => UnitStatus::Vacant]);
            }

            return $property;
        });
    }

    public function test_setup_grid_lists_missing_meters(): void
    {
        $this->actingAsAdmin();

        $this->getJson("/api/admin/properties/{$this->property->id}/meter-setup")->assertOk()
            ->assertJsonPath('data.charges.0.charge_code', 'electricity')
            ->assertJsonPath('data.charges.1.charge_code', 'water')
            ->assertJsonPath('data.missing', 4) // 8-1 and 8-2 × electricity, water
            ->assertJsonPath('data.units.0.unit_number', '7-7')
            ->assertJsonPath('data.units.0.meters.electricity.initial_reading', '100.00')
            ->assertJsonPath('data.units.1.meters.water', null);
    }

    public function test_fit_many_meters_at_once_all_or_nothing(): void
    {
        $this->actingAsAdmin();
        [$u1, $u2] = $this->asKims(fn () => Unit::whereIn('unit_number', ['8-1', '8-2'])->orderBy('unit_number')->get()->all());
        $kims77 = $this->asKims(fn () => Unit::where('unit_number', '7-7')->firstOrFail());
        $url = "/api/admin/properties/{$this->property->id}/meters";

        // One bad row (7-7 already has electricity) → nothing saved, error on that row.
        $this->postJson($url, ['meters' => [
            ['unit_id' => $u1->id, 'charge_code' => 'electricity', 'initial_reading' => '1520', 'meter_number' => 'KPLC-1'],
            ['unit_id' => $kims77->id, 'charge_code' => 'electricity', 'initial_reading' => '5'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('meters.1.charge_code');
        $this->assertSame(2, $this->asKims(fn () => Meter::count()));

        $this->postJson($url, ['meters' => [
            ['unit_id' => $u1->id, 'charge_code' => 'electricity', 'initial_reading' => '1520', 'meter_number' => 'KPLC-1'],
            ['unit_id' => $u1->id, 'charge_code' => 'water', 'initial_reading' => '33'],
            ['unit_id' => $u2->id, 'charge_code' => 'electricity', 'initial_reading' => '880'],
            ['unit_id' => $u2->id, 'charge_code' => 'water', 'initial_reading' => '12.5'],
        ]])->assertAccepted();

        $this->getJson("/api/admin/properties/{$this->property->id}/meter-setup")
            ->assertJsonPath('data.missing', 0)
            ->assertJsonPath('data.units.1.meters.electricity.meter_number', 'KPLC-1');

        // A charge that isn't metered, or another property's unit, is refused per row.
        $other = $this->asKims(fn () => Unit::factory()->create(['property_id' => Property::factory()->create(['account_id' => $this->kims->id])->id]));
        $this->postJson($url, ['meters' => [['unit_id' => $other->id, 'charge_code' => 'water', 'initial_reading' => '1']]])
            ->assertUnprocessable()->assertJsonValidationErrors('meters.0.unit_id');
        $this->postJson($url, ['meters' => [['unit_id' => $u1->id, 'charge_code' => 'garbage', 'initial_reading' => '1']]])
            ->assertUnprocessable()->assertJsonValidationErrors('meters.0.charge_code');
    }

    public function test_caretakers_cannot_fit_meters(): void
    {
        $this->actingAsAdmin('caretaker@rentflow.test');
        $u1 = $this->asKims(fn () => Unit::where('unit_number', '8-1')->firstOrFail());

        $this->getJson("/api/admin/properties/{$this->property->id}/meter-setup")->assertOk();
        $this->postJson("/api/admin/properties/{$this->property->id}/meters", ['meters' => [['unit_id' => $u1->id, 'charge_code' => 'water', 'initial_reading' => '1']]])->assertForbidden();
    }
}
