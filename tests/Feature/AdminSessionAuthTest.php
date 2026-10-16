<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** Cookie-session login for the React admin portal (http://localhost:5173 is a Sanctum stateful origin). */
class AdminSessionAuthTest extends TestCase
{
    use BillsKimsProperties, RefreshDatabase;

    private const SPA = ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/login'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
    }

    public function test_the_spa_logs_in_with_a_cookie_session(): void
    {
        $this->login('landlord@rentflow.test')
            ->assertOk()
            ->assertJsonPath('user.roles', ['Owner'])
            ->assertJsonPath('user.account_id', $this->kims->id);

        $user = User::where('email', 'landlord@rentflow.test')->firstOrFail();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotNull($user->last_login_at);

        $this->getJson('/api/admin/user', self::SPA)->assertOk()->assertJsonPath('user.email', 'landlord@rentflow.test');
    }

    public function test_wrong_password_is_a_validation_error(): void
    {
        $this->login('landlord@rentflow.test', 'wrong')->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest('web');
    }

    public function test_suspended_account_cannot_log_in(): void
    {
        Account::whereKey($this->kims->id)->update(['status' => 'suspended']);

        $this->login('landlord@rentflow.test')->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_logout_ends_the_admin_session(): void
    {
        $this->login('landlord@rentflow.test')->assertOk();

        $this->postJson('/api/admin/auth/logout', [], self::SPA)->assertNoContent();

        $this->assertGuest('web');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/user', self::SPA)->assertUnauthorized();
    }

    private function login(string $email, string $password = 'password')
    {
        return $this->postJson('/api/admin/auth/login', ['email' => $email, 'password' => $password], self::SPA);
    }
}
