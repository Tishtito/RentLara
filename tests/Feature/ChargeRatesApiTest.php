<?php

namespace Tests\Feature;

use App\Models\ChargeRate;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** Billing settings: versioned charge rates and the account's billing day. */
class ChargeRatesApiTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, RefreshDatabase;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        $this->property = $this->asKims(fn () => Property::firstOrFail());
    }

    public function test_lists_every_version_and_changes_a_price_from_a_date(): void
    {
        $this->actingAsAdmin();

        $this->getJson("/api/admin/properties/{$this->property->id}/charge-rates")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.charge_code', 'electricity')
            ->assertJsonPath('data.0.effective_to', null);

        $this->postJson("/api/admin/properties/{$this->property->id}/charge-rates", ['charge_code' => 'electricity', 'rate' => '32', 'effective_from' => '2026-11-01'])
            ->assertAccepted();

        $versions = $this->getJson("/api/admin/properties/{$this->property->id}/charge-rates")->json('data');
        $electricity = array_values(array_filter($versions, fn ($r) => $r['charge_code'] === 'electricity'));
        $this->assertSame(['32.00', '30.00'], array_column($electricity, 'rate'));
        $this->assertSame([null, '2026-10-31'], array_column($electricity, 'effective_to'));
        $this->assertSame('Electricity', $electricity[0]['label']); // carried over
        $this->assertSame('Kim Landlord', $electricity[0]['created_by_name']);
    }

    public function test_new_charges_overrides_and_validation(): void
    {
        $this->actingAsAdmin();
        $url = "/api/admin/properties/{$this->property->id}/charge-rates";
        $unit = $this->asKims(fn () => Unit::firstOrFail());

        // A new charge needs its type.
        $this->postJson($url, ['charge_code' => 'Security Fee', 'label' => 'Security', 'rate' => '500', 'effective_from' => '2026-11-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('billing_type');
        $this->postJson($url, ['charge_code' => 'Security Fee', 'label' => 'Security', 'billing_type' => 'fixed', 'rate' => '500', 'effective_from' => '2026-11-01'])
            ->assertAccepted();
        $this->asKims(fn () => $this->assertSame('500.00', ChargeRate::where('charge_code', 'security_fee')->value('rate')));

        // A price for one unit only.
        $this->postJson($url, ['charge_code' => 'garbage', 'rate' => '0', 'effective_from' => '2026-11-01', 'unit_id' => $unit->id])->assertAccepted();
        $this->asKims(fn () => $this->assertSame(2, ChargeRate::where('charge_code', 'garbage')->whereNull('effective_to')->count()));

        // Starting before the current price started is refused (422, not a server error).
        $this->postJson($url, ['charge_code' => 'water', 'rate' => '180', 'effective_from' => '2025-12-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('effective_from');

        // A unit from another property can't get an override here.
        $otherUnit = $this->asKims(fn () => Unit::factory()->create(['property_id' => Property::factory()->create(['account_id' => $this->kims->id])->id]));
        $this->postJson($url, ['charge_code' => 'water', 'rate' => '180', 'effective_from' => '2026-11-01', 'unit_id' => $otherUnit->id])
            ->assertUnprocessable()->assertJsonValidationErrors('unit_id');
    }

    public function test_billing_day_settings_move_the_next_bill(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/account')->assertOk()->assertJsonPath('data.billing_day', 5)->assertJsonPath('data.invoice_prefix', 'INV');
        $this->putJson('/api/admin/account/billing', ['billing_day' => 10, 'payment_due_days' => 7, 'invoice_prefix' => 'kp'])->assertAccepted();
        $this->getJson('/api/admin/account')->assertJsonPath('data.billing_day', 10)->assertJsonPath('data.invoice_prefix', 'KP');

        // 7-7 was due 5 Nov; the first 10th on or after that is 10 Nov.
        $this->assertSame('2026-11-10', $this->asKims(fn () => Lease::firstOrFail())->next_billing_date->toDateString());

        $this->putJson('/api/admin/account/billing', ['billing_day' => 29, 'payment_due_days' => 7, 'invoice_prefix' => 'INV-'])
            ->assertUnprocessable()->assertJsonValidationErrors(['billing_day', 'invoice_prefix']);
    }

    public function test_caretakers_can_see_prices_but_not_change_them(): void
    {
        $this->actingAsAdmin('caretaker@rentflow.test');

        $this->getJson("/api/admin/properties/{$this->property->id}/charge-rates")->assertOk();
        $this->postJson("/api/admin/properties/{$this->property->id}/charge-rates", ['charge_code' => 'water', 'rate' => '1', 'effective_from' => '2026-11-01'])->assertForbidden();
        $this->putJson('/api/admin/account/billing', ['billing_day' => 5, 'payment_due_days' => 5, 'invoice_prefix' => 'INV'])->assertForbidden();
    }

    public function test_another_landlords_property_is_not_found(): void
    {
        $this->actingAsAdmin();
        $other = Property::factory()->create();

        $this->getJson("/api/admin/properties/{$other->id}/charge-rates")->assertNotFound();
        $this->postJson("/api/admin/properties/{$other->id}/charge-rates", ['charge_code' => 'water', 'rate' => '1', 'effective_from' => '2026-11-01'])->assertNotFound();
    }
}
