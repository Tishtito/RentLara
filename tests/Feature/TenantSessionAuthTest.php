<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** Cookie-session login for the React tenant portal (http://localhost:5174 is a Sanctum stateful origin). */
class TenantSessionAuthTest extends TestCase
{
    use BillsKimsProperties, RefreshDatabase;

    private const SPA = ['Origin' => 'http://localhost:5174', 'Referer' => 'http://localhost:5174/login'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
    }

    public function test_a_tenant_logs_in_with_email_or_national_id(): void
    {
        $this->login('jane@rentflow.test')
            ->assertOk()
            ->assertJsonPath('choose_home', false)
            ->assertJsonPath('tenant.full_name', 'Jane Wanjiru')
            ->assertJsonPath('tenant.account.name', 'Kims Properties');

        $this->assertAuthenticated('tenant');
        $this->getJson('/api/portal/user', self::SPA)->assertOk()->assertJsonPath('tenant.email', 'jane@rentflow.test');

        $this->app['auth']->forgetGuards();
        $this->login('12345678')->assertOk()->assertJsonPath('tenant.full_name', 'Jane Wanjiru');
    }

    public function test_wrong_password_is_a_validation_error(): void
    {
        $this->login('jane@rentflow.test', 'wrong')->assertUnprocessable()->assertJsonValidationErrors('identifier');
        $this->assertGuest('tenant');
    }

    public function test_a_person_renting_from_two_landlords_chooses_a_home(): void
    {
        $other = $this->secondHome();

        $homes = $this->login('jane@rentflow.test')
            ->assertOk()
            ->assertJsonPath('choose_home', true)
            ->assertJsonCount(2, 'homes')
            ->json('homes');

        $this->assertGuest('tenant');
        $this->assertEqualsCanonicalizing(['Kims Properties', 'Mwangi Homes'], array_column($homes, 'account_name'));

        $this->login('jane@rentflow.test', 'password', $other->id)
            ->assertOk()
            ->assertJsonPath('tenant.id', $other->id)
            ->assertJsonPath('tenant.account.name', 'Mwangi Homes');

        $this->assertAuthenticatedAs($other, 'tenant');
    }

    public function test_only_homes_whose_password_matches_are_offered(): void
    {
        $other = $this->secondHome(password: 'different');

        // Only the Kims record matches "password", so there's nothing to choose: log straight in.
        $this->login('jane@rentflow.test')->assertOk()->assertJsonPath('choose_home', false)->assertJsonPath('tenant.account.name', 'Kims Properties');

        // Picking the other landlord's record with the wrong password is refused.
        $this->app['auth']->forgetGuards();
        $this->login('jane@rentflow.test', 'password', $other->id)->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
    }

    public function test_logging_out_of_the_portal_keeps_an_admin_session(): void
    {
        $this->postJson('/api/admin/auth/login', ['email' => 'landlord@rentflow.test', 'password' => 'password'], self::SPA)->assertOk();
        $this->login('jane@rentflow.test')->assertOk();

        $this->postJson('/api/portal/auth/logout', [], self::SPA)->assertNoContent();

        $this->assertGuest('tenant');
        $this->assertAuthenticated('web');
    }

    private function secondHome(string $password = 'password'): Tenant
    {
        $account = Account::factory()->create(['name' => 'Mwangi Homes', 'slug' => 'mwangi-homes']);

        return Tenant::factory()->create([
            'account_id' => $account->id,
            'first_name' => 'Jane',
            'last_name' => 'Wanjiru',
            'email' => 'jane@rentflow.test',
            'password' => $password,
        ]);
    }

    private function login(string $identifier, string $password = 'password', ?int $tenantId = null)
    {
        return $this->postJson('/api/portal/auth/login', array_filter([
            'identifier' => $identifier,
            'password' => $password,
            'tenant_id' => $tenantId,
        ]), self::SPA);
    }
}
