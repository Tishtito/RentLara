<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** Each portal's routes only accept its own kind of login (EnsureActor). */
class AuthBoundaryTest extends TestCase
{
    use BillsKimsProperties, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
    }

    public function test_a_tenant_token_cannot_use_the_admin_api(): void
    {
        $token = $this->postJson('/api/portal/auth/token', ['identifier' => 'jane@rentflow.test', 'password' => 'password'])->json('token');
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->getJson('/api/admin/user', $headers)->assertUnauthorized();
        $this->getJson('/api/admin/payments', $headers)->assertUnauthorized();
        $this->postJson('/api/admin/billing-runs', [], $headers)->assertUnauthorized();
        $this->deleteJson('/api/admin/auth/token', [], $headers)->assertUnauthorized();
    }

    public function test_an_admin_token_cannot_act_as_a_tenant(): void
    {
        $token = $this->postJson('/api/admin/auth/token', ['email' => 'landlord@rentflow.test', 'password' => 'password'])->json('token');
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->getJson('/api/portal/user', $headers)->assertUnauthorized();
        $this->deleteJson('/api/portal/auth/token', [], $headers)->assertUnauthorized();

        // ...but it still works on the admin API.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/user', $headers)->assertOk();
    }

    public function test_an_admin_cookie_session_is_not_a_tenant_login(): void
    {
        $spa = ['Origin' => 'http://localhost:5174', 'Referer' => 'http://localhost:5174/'];
        $this->postJson('/api/admin/auth/login', ['email' => 'landlord@rentflow.test', 'password' => 'password'], $spa)->assertOk();

        $this->getJson('/api/portal/user', $spa)->assertUnauthorized();
    }
}
