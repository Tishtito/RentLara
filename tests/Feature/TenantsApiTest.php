<?php

namespace Tests\Feature;

use App\Enums\LeaseStatus;
use App\Enums\TenantStatus;
use App\Enums\UnitStatus;
use App\Mail\TenantInvitationMail;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\TenantAuthToken;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** Move-in, tenants, invitations (email + accept) and move-out. */
class TenantsApiTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, RefreshDatabase;

    private Unit $vacant;

    /** @var array<string, Meter> */
    private array $meters;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        Mail::fake();

        [$this->vacant, $this->meters] = $this->asKims(function () {
            $unit = Unit::factory()->create(['property_id' => Property::firstOrFail()->id, 'unit_number' => '7-8', 'status' => UnitStatus::Vacant]);
            $meters = [];
            foreach (['electricity' => '500', 'water' => '40'] as $code => $initial) {
                $meters[$code] = Meter::create(['unit_id' => $unit->id, 'charge_code' => $code, 'initial_reading' => $initial, 'is_active' => true]);
            }

            return [$unit, $meters];
        });
    }

    public function test_move_in_creates_tenant_lease_readings_and_emails_the_invitation(): void
    {
        $this->actingAsAdmin();

        $this->moveIn()->assertAccepted();

        $this->asKims(function () {
            $tenant = Tenant::where('email', 'peter@example.com')->firstOrFail();
            $this->assertSame('+254722000481', $tenant->phone);
            $this->assertSame(TenantStatus::Invited, $tenant->status);

            $lease = Lease::where('tenant_id', $tenant->id)->firstOrFail();
            $this->assertSame('2026-10-01', $lease->start_date->toDateString());
            $this->assertSame('2026-10-05', $lease->next_billing_date->toDateString()); // first billing day after move-in
            $this->assertSame('500.00', $lease->opening_balance);
            $this->assertSame(UnitStatus::Occupied, $this->vacant->fresh()->status);

            $this->assertSame('520.00', $this->meters['electricity']->readings()->value('reading'));
            $this->assertSame('2026-10-01', $this->meters['water']->readings()->first()->reading_date->toDateString());
        });

        Mail::assertSent(TenantInvitationMail::class, fn (TenantInvitationMail $m) => $m->hasTo('peter@example.com')
            && str_starts_with($m->link, 'http://localhost:5174/invite/')
            && $m->home === 'House 7-8, Kims Apartments');

        // Render it for real (Mail::fake never compiles the template).
        Mail::assertSent(TenantInvitationMail::class, function (TenantInvitationMail $m) {
            $html = $m->render();
            $this->assertStringContainsString('Karibu, Peter', $html);
            $this->assertStringContainsString('House 7-8, Kims Apartments', $html);
            $this->assertStringContainsString('or your national ID', $html);
            $this->assertStringContainsString(e($m->link), $html);

            return true;
        });
    }

    public function test_move_in_validation(): void
    {
        $this->actingAsAdmin();

        // Missing an opening reading, bad phone, a duplicate email and a reading below the meter's start.
        $this->moveIn(['opening_readings' => [$this->meters['electricity']->id => '520']])
            ->assertUnprocessable()->assertJsonValidationErrors("opening_readings.{$this->meters['water']->id}");
        $this->moveIn(['tenant' => ['first_name' => 'P', 'last_name' => 'M', 'phone' => '123']])
            ->assertUnprocessable()->assertJsonValidationErrors('tenant.phone');
        $this->moveIn(['tenant' => ['first_name' => 'P', 'last_name' => 'M', 'phone' => '0722000481', 'email' => 'jane@rentflow.test']])
            ->assertUnprocessable()->assertJsonValidationErrors('tenant.email');
        $this->moveIn(['opening_readings' => [$this->meters['electricity']->id => '10', $this->meters['water']->id => '41']])
            ->assertUnprocessable()->assertJsonValidationErrors("opening_readings.{$this->meters['electricity']->id}");

        // Nothing half-written.
        $this->asKims(fn () => $this->assertSame(0, Lease::where('unit_id', $this->vacant->id)->count()));
        $this->assertSame(UnitStatus::Vacant, $this->vacant->fresh()->status);

        // An occupied unit can't take a second tenant.
        $occupied = $this->asKims(fn () => Unit::where('unit_number', '7-7')->firstOrFail());
        $this->postJson("/api/admin/units/{$occupied->id}/move-in", $this->body())->assertUnprocessable()->assertJsonValidationErrors('unit');
    }

    public function test_tenant_accepts_the_invitation_and_is_logged_in(): void
    {
        $this->actingAsAdmin();
        $this->moveIn()->assertAccepted();
        $link = null;
        Mail::assertSent(TenantInvitationMail::class, function (TenantInvitationMail $m) use (&$link) {
            $link = $m->link;

            return true;
        });
        $token = Str::afterLast($link, '/invite/');
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/portal/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('first_name', 'Peter')
            ->assertJsonPath('landlord', 'Kims Properties')
            ->assertJsonPath('home', 'House 7-8, Kims Apartments')
            ->assertJsonPath('log_in_with', ['peter@example.com', 'ID 29384756']);

        $spa = ['Origin' => 'http://localhost:5174', 'Referer' => 'http://localhost:5174/invite'];
        $this->postJson("/api/portal/invitations/{$token}", ['password' => 'short', 'password_confirmation' => 'short'], $spa)
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson("/api/portal/invitations/{$token}", ['password' => 'new-password-1', 'password_confirmation' => 'new-password-1'], $spa)
            ->assertOk()->assertJsonPath('tenant.full_name', 'Peter Mwangi');
        $this->assertAuthenticated('tenant');

        // Works once; then the tenant logs in normally with email or national ID.
        $this->getJson("/api/portal/invitations/{$token}")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->postJson('/api/portal/auth/login', ['identifier' => '29384756', 'password' => 'new-password-1'], $spa)
            ->assertOk()->assertJsonPath('tenant.account.name', 'Kims Properties');
        $this->assertSame(TenantStatus::Active, Tenant::withoutGlobalScopes()->where('email', 'peter@example.com')->value('status'));
    }

    public function test_expired_links_and_resending(): void
    {
        $this->actingAsAdmin();
        $this->moveIn()->assertAccepted();
        $tenant = $this->asKims(fn () => Tenant::where('email', 'peter@example.com')->firstOrFail());

        $this->postJson("/api/admin/tenants/{$tenant->id}/invitation")->assertAccepted();
        Mail::assertSent(TenantInvitationMail::class, 2);
        // Only the newest link works.
        $this->asKims(fn () => $this->assertSame(1, TenantAuthToken::where('tenant_id', $tenant->id)->where('expires_at', '>', now())->count()));

        $this->travel(4)->days();
        $this->getJson('/api/portal/invitations/'.Str::random(64))->assertNotFound();

        // No email → nothing to send; Jane is already active.
        $jane = $this->asKims(fn () => Tenant::where('email', 'jane@rentflow.test')->firstOrFail());
        $this->postJson("/api/admin/tenants/{$jane->id}/invitation")->assertUnprocessable()->assertJsonValidationErrors('tenant');
    }

    public function test_tenants_list_search_detail_and_edit(): void
    {
        $this->actingAsAdmin();
        $this->runBilling();
        $this->moveIn()->assertAccepted();

        $this->getJson('/api/admin/tenants')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/tenants?search=jane+7-7')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.current_lease.unit_number', '7-7')
            ->assertJsonPath('data.0.current_lease.balance', '19500.00');

        $jane = $this->asKims(fn () => Tenant::where('email', 'jane@rentflow.test')->firstOrFail());
        $this->getJson("/api/admin/tenants/{$jane->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.leases')
            ->assertJsonCount(1, 'data.invoices')
            ->assertJsonPath('data.leases.0.property_name', 'Kims Apartments');

        $this->putJson("/api/admin/tenants/{$jane->id}", ['first_name' => 'Jane', 'last_name' => 'Wanjiru-Kamau', 'phone' => '0712 999 888'])->assertAccepted();
        $this->assertSame('+254712999888', $jane->fresh()->phone);
        $this->putJson("/api/admin/tenants/{$jane->id}", ['first_name' => 'Jane', 'last_name' => 'W', 'phone' => '0712999888', 'email' => 'peter@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_move_out_frees_the_unit_and_keeps_the_balance(): void
    {
        $this->actingAsAdmin();
        $this->runBilling();
        $lease = $this->asKims(fn () => Lease::firstOrFail());

        $this->postJson("/api/admin/leases/{$lease->id}/end", ['end_date' => '2026-08-01'])->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->postJson("/api/admin/leases/{$lease->id}/end", ['end_date' => '2026-10-15'])->assertAccepted();

        $this->assertSame(LeaseStatus::Ended, $lease->fresh()->status);
        $this->assertSame(UnitStatus::Vacant, $lease->unit->fresh()->status);
        $jane = $this->asKims(fn () => Tenant::where('email', 'jane@rentflow.test')->firstOrFail());
        $this->getJson("/api/admin/tenants/{$jane->id}")->assertJsonPath('data.leases.0.balance', '19500.00');

        $this->postJson("/api/admin/leases/{$lease->id}/end", ['end_date' => '2026-10-16'])->assertUnprocessable()->assertJsonValidationErrors('lease');
    }

    public function test_caretakers_can_look_but_not_change(): void
    {
        $this->actingAsAdmin('caretaker@rentflow.test');
        $jane = $this->asKims(fn () => Tenant::where('email', 'jane@rentflow.test')->firstOrFail());

        $this->getJson('/api/admin/tenants')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/admin/tenants/{$jane->id}")->assertOk();
        $this->putJson("/api/admin/tenants/{$jane->id}", ['first_name' => 'X', 'last_name' => 'Y', 'phone' => '0712345678'])->assertForbidden();
        $this->moveIn()->assertForbidden();
    }

    public function test_other_landlords_tenants_and_units_are_not_found(): void
    {
        $this->actingAsAdmin();
        $otherLease = Lease::factory()->create();
        $otherUnit = Unit::factory()->create();

        $this->getJson("/api/admin/tenants/{$otherLease->tenant_id}")->assertNotFound();
        $this->postJson("/api/admin/units/{$otherUnit->id}/move-in", $this->body())->assertNotFound();
        $this->postJson("/api/admin/leases/{$otherLease->id}/end", ['end_date' => '2026-10-15'])->assertNotFound();
    }

    private function body(array $overrides = []): array
    {
        return array_replace([
            'tenant' => ['first_name' => 'Peter', 'last_name' => 'Mwangi', 'phone' => '0722 000 481', 'email' => 'Peter@Example.com', 'national_id' => '29384756'],
            'lease' => ['monthly_rent' => '15000', 'deposit_amount' => '15000', 'start_date' => '2026-10-01', 'opening_balance' => '500'],
            'opening_readings' => [$this->meters['electricity']->id => '520', $this->meters['water']->id => '41'],
        ], $overrides);
    }

    private function moveIn(array $overrides = [])
    {
        return $this->postJson("/api/admin/units/{$this->vacant->id}/move-in", $this->body($overrides));
    }
}
