<?php

namespace Tests\Feature;

use App\Enums\UnitStatus;
use App\Models\Account;
use App\Models\Meter;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** Properties, units and meters (admin API). Seed: Kims Apartments, unit 7-7 occupied, 1,000 arrears. */
class PropertiesApiTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
    }

    public function test_landlord_lists_properties_with_counts_and_arrears(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/properties')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Kims Apartments')
            ->assertJsonPath('data.0.units_count', 1)
            ->assertJsonPath('data.0.occupied_count', 1)
            ->assertJsonPath('data.0.vacant_count', 0)
            ->assertJsonPath('data.0.arrears_total', '1000.00')
            ->assertJsonPath('data.0.caretaker.name', 'Kims Caretaker');
    }

    public function test_units_show_the_current_tenant_balance_and_meters(): void
    {
        $this->actingAsAdmin();
        $this->runBilling(); // bill 1: 18,500 + 1,000 arrears

        $property = $this->asKims(fn () => Property::firstOrFail());

        $this->getJson("/api/admin/properties/{$property->id}/units")
            ->assertOk()
            ->assertJsonPath('data.0.unit_number', '7-7')
            ->assertJsonPath('data.0.current_lease.tenant_name', 'Jane Wanjiru')
            ->assertJsonPath('data.0.current_lease.balance', '19500.00')
            ->assertJsonCount(2, 'data.0.meters');
    }

    public function test_landlord_adds_and_edits_a_property_and_units(): void
    {
        $this->actingAsAdmin();
        $caretaker = User::where('email', 'caretaker@rentflow.test')->firstOrFail();

        $this->postJson('/api/admin/properties', ['name' => 'Ruaka Heights', 'town' => 'Ruaka', 'caretaker_id' => $caretaker->id])->assertAccepted();
        $this->postJson('/api/admin/properties', ['name' => 'Ruaka Heights'])->assertUnprocessable()->assertJsonValidationErrors('name');

        $ruaka = $this->asKims(fn () => Property::where('name', 'Ruaka Heights')->firstOrFail());
        $this->putJson("/api/admin/properties/{$ruaka->id}", ['name' => 'Ruaka Heights Block A', 'town' => 'Ruaka'])->assertAccepted();

        $this->postJson("/api/admin/properties/{$ruaka->id}/units", ['unit_number' => 'A1', 'default_rent' => '12000'])->assertAccepted();
        $this->postJson("/api/admin/properties/{$ruaka->id}/units", ['unit_number' => 'A1', 'default_rent' => '12000'])
            ->assertUnprocessable()->assertJsonValidationErrors('unit_number');

        $unit = $this->asKims(fn () => Unit::where('unit_number', 'A1')->firstOrFail());
        $this->assertSame(UnitStatus::Vacant, $unit->status);
        $this->assertSame('Ruaka Heights Block A', $unit->property->name);

        // "occupied" only comes from move-in.
        $this->putJson("/api/admin/units/{$unit->id}", ['unit_number' => 'A1', 'default_rent' => '12500', 'status' => 'occupied'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->putJson("/api/admin/units/{$unit->id}", ['unit_number' => 'A1', 'default_rent' => '12500', 'status' => 'maintenance'])->assertAccepted();
        $this->assertSame('12500.00', $unit->fresh()->default_rent);
    }

    public function test_editing_a_property_keeps_fields_it_does_not_send(): void
    {
        $this->actingAsAdmin();
        $caretaker = User::where('email', 'caretaker@rentflow.test')->firstOrFail();

        $this->postJson('/api/admin/properties', ['name' => 'Ruaka Heights', 'address' => 'Off Limuru Rd', 'caretaker_id' => $caretaker->id, 'notes' => 'Gate code 4471'])->assertAccepted();
        $ruaka = $this->asKims(fn () => Property::where('name', 'Ruaka Heights')->firstOrFail());

        $this->putJson("/api/admin/properties/{$ruaka->id}", ['name' => 'Ruaka Heights A', 'town' => 'Ruaka'])->assertAccepted();
        $this->getJson("/api/admin/properties/{$ruaka->id}")->assertOk()
            ->assertJsonPath('data.name', 'Ruaka Heights A')
            ->assertJsonPath('data.town', 'Ruaka')
            ->assertJsonPath('data.address', 'Off Limuru Rd')
            ->assertJsonPath('data.notes', 'Gate code 4471')
            ->assertJsonPath('data.caretaker.id', $caretaker->id)
            ->assertJsonPath('data.status', 'active');

        // Sending a field empty clears it; status can be changed.
        $this->putJson("/api/admin/properties/{$ruaka->id}", ['name' => 'Ruaka Heights A', 'notes' => null, 'caretaker_id' => null, 'status' => 'inactive'])->assertAccepted();
        $this->getJson("/api/admin/properties/{$ruaka->id}")
            ->assertJsonPath('data.notes', null)
            ->assertJsonPath('data.caretaker', null)
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.address', 'Off Limuru Rd');

        $this->putJson("/api/admin/properties/{$ruaka->id}", ['name' => 'Kims Apartments'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson("/api/admin/properties/{$ruaka->id}", ['name' => 'X', 'status' => 'gone'])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->actingAsAdmin('caretaker@rentflow.test');
        $this->putJson("/api/admin/properties/{$ruaka->id}", ['name' => 'Caretaker rename'])->assertForbidden();
    }

    public function test_an_occupied_unit_cannot_be_marked_vacant_by_editing(): void
    {
        $this->actingAsAdmin();
        $unit = $this->asKims(fn () => Unit::where('unit_number', '7-7')->firstOrFail());

        $this->putJson("/api/admin/units/{$unit->id}", ['unit_number' => '7-7', 'default_rent' => '15000', 'status' => 'vacant'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_meters_one_per_metered_charge_and_replacement(): void
    {
        $this->actingAsAdmin();
        $unit = $this->asKims(fn () => Unit::where('unit_number', '7-7')->firstOrFail());
        $old = $this->asKims(fn () => Meter::where('unit_id', $unit->id)->where('charge_code', 'water')->firstOrFail());

        // Garbage is a fixed charge; electricity already has a meter.
        $this->postJson("/api/admin/units/{$unit->id}/meters", ['charge_code' => 'garbage', 'initial_reading' => '0'])
            ->assertUnprocessable()->assertJsonValidationErrors('charge_code');
        $this->postJson("/api/admin/units/{$unit->id}/meters", ['charge_code' => 'electricity', 'initial_reading' => '0'])
            ->assertUnprocessable()->assertJsonValidationErrors('charge_code');

        $this->putJson("/api/admin/meters/{$old->id}", ['meter_number' => 'W-NEW', 'initial_reading' => '0'])->assertAccepted();

        $this->asKims(function () use ($old, $unit) {
            $this->assertFalse($old->fresh()->is_active);
            $this->assertSame('W-NEW', Meter::where('unit_id', $unit->id)->where('charge_code', 'water')->where('is_active', true)->value('meter_number'));
        });
    }

    public function test_caretakers_see_only_their_properties_and_cannot_change_them(): void
    {
        $this->asKims(fn () => Property::factory()->create(['account_id' => $this->kims->id, 'name' => 'Not Mine']));
        $this->actingAsAdmin('caretaker@rentflow.test');

        $this->getJson('/api/admin/properties')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Kims Apartments');

        $notMine = $this->asKims(fn () => Property::where('name', 'Not Mine')->firstOrFail());
        $this->getJson("/api/admin/properties/{$notMine->id}/units")->assertForbidden();
        $this->postJson('/api/admin/properties', ['name' => 'Mine Now'])->assertForbidden();
        $this->getJson('/api/admin/caretakers')->assertForbidden();
    }

    public function test_another_landlords_property_is_not_found(): void
    {
        $other = Property::factory()->create(); // a different account
        $this->actingAsAdmin();

        $this->getJson("/api/admin/properties/{$other->id}")->assertNotFound();
        $this->getJson("/api/admin/properties/{$other->id}/units")->assertNotFound();
        $this->postJson("/api/admin/properties/{$other->id}/units", ['unit_number' => 'X', 'default_rent' => '1'])->assertNotFound();

        // A caretaker from another account can't be assigned either.
        $stranger = User::factory()->caretaker()->create(['account_id' => Account::factory()]);
        $this->postJson('/api/admin/properties', ['name' => 'Ruaka', 'caretaker_id' => $stranger->id])
            ->assertUnprocessable()->assertJsonValidationErrors('caretaker_id');
    }
}
