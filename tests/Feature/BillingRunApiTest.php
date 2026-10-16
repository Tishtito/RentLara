<?php

namespace Tests\Feature;

use App\Models\BillingRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

class BillingRunApiTest extends TestCase
{
    use BillsKimsProperties, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
    }

    public function test_landlord_can_trigger_a_manual_run(): void
    {
        Sanctum::actingAs(User::where('email', 'landlord@rentflow.test')->firstOrFail());

        $this->postJson('/api/admin/billing-runs')->assertAccepted();

        $run = $this->asKims(fn () => BillingRun::sole());
        $this->assertSame('manual', $run->trigger);
        $this->assertSame(1, $run->invoices_created);

        $this->getJson('/api/admin/billing-runs')
            ->assertOk()
            ->assertJsonPath('data.0.invoices_created', 1);
    }

    public function test_caretaker_cannot_trigger_a_run(): void
    {
        Sanctum::actingAs(User::where('email', 'caretaker@rentflow.test')->firstOrFail());

        $this->postJson('/api/admin/billing-runs')->assertForbidden();
    }

    public function test_super_admin_must_choose_an_account(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/admin/billing-runs')->assertUnprocessable()->assertJsonValidationErrors('account');

        $this->postJson('/api/admin/billing-runs', [], ['X-Account-Id' => $this->kims->id])->assertAccepted();
        $this->assertSame(1, $this->asKims(fn () => BillingRun::count()));
    }
}
