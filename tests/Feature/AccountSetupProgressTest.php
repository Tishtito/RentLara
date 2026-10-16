<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** The getting-started checklist for a new landlord. */
class AccountSetupProgressTest extends TestCase
{
    use BillsKimsProperties, RefreshDatabase;

    public function test_a_new_landlord_starts_with_nothing_done_and_ticks_steps_off(): void
    {
        $this->seed(DatabaseSeeder::class); // permission templates
        $account = Account::factory()->create(['name' => 'New Landlord']);
        $owner = User::factory()->create(['account_id' => $account->id]);
        Sanctum::actingAs($owner);

        $steps = fn () => collect($this->getJson('/api/admin/setup')->assertOk()->json('data.steps'))->pluck('done', 'key')->all();
        $this->assertSame(['property' => false, 'prices' => false, 'units' => false, 'meters' => false, 'billing_day' => false, 'tenants' => false], $steps());
        $this->assertFalse($this->getJson('/api/admin/setup')->json('data.complete'));

        $this->postJson('/api/admin/properties', ['name' => 'Ruaka Flats'])->assertAccepted();
        $property = $this->getJson('/api/admin/properties')->json('data.0.id');
        $this->postJson("/api/admin/properties/{$property}/charge-rates", ['charge_code' => 'water', 'label' => 'Water', 'billing_type' => 'metered', 'unit_of_measure' => 'm³', 'rate' => '170', 'effective_from' => '2026-11-01'])->assertAccepted();
        $this->postJson("/api/admin/properties/{$property}/units", ['unit_number' => 'A1', 'default_rent' => '9000'])->assertAccepted();
        $this->putJson('/api/admin/account/billing', ['billing_day' => 5, 'payment_due_days' => 5, 'invoice_prefix' => 'RF'])->assertAccepted();

        $this->assertSame(['property' => true, 'prices' => true, 'units' => true, 'meters' => false, 'billing_day' => true, 'tenants' => false], $steps());
        $this->assertStringEndsWith("/admin/properties/{$property}/meters", collect($this->getJson('/api/admin/setup')->json('data.steps'))->firstWhere('key', 'meters')['link']);
    }

    public function test_kims_is_almost_complete(): void
    {
        $this->seedKims();
        Sanctum::actingAs(User::where('email', 'landlord@rentflow.test')->firstOrFail());

        $done = collect($this->getJson('/api/admin/setup')->json('data.steps'))->pluck('done', 'key')->all();
        $this->assertSame(['property' => true, 'prices' => true, 'units' => true, 'meters' => true, 'billing_day' => false, 'tenants' => true], $done);
    }
}
