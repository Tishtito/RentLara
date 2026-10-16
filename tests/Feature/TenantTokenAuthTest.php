<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** Tenant API tokens for the Android/iOS apps (Capacitor web views on https://localhost / capacitor://localhost). */
class TenantTokenAuthTest extends TestCase
{
    use BillsKimsProperties, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
    }

    public function test_the_app_logs_in_with_a_token_from_its_web_view_origin(): void
    {
        foreach (['https://localhost', 'capacitor://localhost'] as $origin) {
            $this->app['auth']->forgetGuards();

            $token = $this->issue('12345678', origin: $origin)
                ->assertOk()
                ->assertJsonPath('choose_home', false)
                ->assertJsonPath('token_type', 'Bearer')
                ->assertJsonPath('tenant.full_name', 'Jane Wanjiru')
                ->assertHeader('Access-Control-Allow-Origin', $origin)
                ->json('token');

            $this->app['auth']->forgetGuards();
            $this->getJson('/api/portal/user', ['Authorization' => "Bearer {$token}", 'Origin' => $origin])
                ->assertOk()
                ->assertJsonPath('tenant.account.name', 'Kims Properties');
        }

        $this->assertNotNull(Tenant::withoutGlobalScopes()->where('email', 'jane@rentflow.test')->value('last_login_at'));
    }

    public function test_wrong_password_is_a_validation_error(): void
    {
        $this->issue('jane@rentflow.test', 'nope')->assertUnprocessable()->assertJsonValidationErrors('identifier');
    }

    public function test_a_person_renting_from_two_landlords_chooses_a_home_first(): void
    {
        $account = Account::factory()->create(['name' => 'Mwangi Homes', 'slug' => 'mwangi-homes']);
        $other = Tenant::factory()->create(['account_id' => $account->id, 'email' => 'jane@rentflow.test', 'password' => 'password']);

        $this->issue('jane@rentflow.test')
            ->assertOk()
            ->assertJsonPath('choose_home', true)
            ->assertJsonCount(2, 'homes')
            ->assertJsonMissingPath('token');

        $this->issue('jane@rentflow.test', tenantId: $other->id)
            ->assertOk()
            ->assertJsonPath('tenant.id', $other->id)
            ->assertJsonPath('tenant.account.name', 'Mwangi Homes');
    }

    public function test_revoking_the_token_logs_the_app_out(): void
    {
        $token = $this->issue('jane@rentflow.test')->json('token');
        $headers = ['Authorization' => "Bearer {$token}", 'Origin' => 'https://localhost'];

        $this->deleteJson('/api/portal/auth/token', [], $headers)->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/portal/user', $headers)->assertUnauthorized();
    }

    private function issue(string $identifier, string $password = 'password', ?int $tenantId = null, string $origin = 'https://localhost')
    {
        return $this->postJson('/api/portal/auth/token', array_filter([
            'identifier' => $identifier,
            'password' => $password,
            'tenant_id' => $tenantId,
            'device_name' => 'tests',
        ]), ['Origin' => $origin]);
    }
}
