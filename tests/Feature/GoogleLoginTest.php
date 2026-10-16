<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BillsKimsProperties;
use Tests\Concerns\FakesGoogleSignIn;
use Tests\TestCase;

/** "Continue with Google": existing logins only, both portals, session (browser) and token (apps). */
class GoogleLoginTest extends TestCase
{
    use BillsKimsProperties, FakesGoogleSignIn, RefreshDatabase;

    private const ADMIN_SPA = ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/login'];

    private const PORTAL_SPA = ['Origin' => 'http://localhost:5174', 'Referer' => 'http://localhost:5174/login'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        $this->fakeGoogle();
    }

    public function test_a_landlord_logs_in_with_google_in_the_browser_and_the_app(): void
    {
        $this->postJson('/api/admin/auth/google', ['credential' => $this->googleIdToken('landlord@rentflow.test')], self::ADMIN_SPA)
            ->assertOk()
            ->assertJsonPath('user.roles', ['Owner']);
        $this->assertAuthenticated('web');

        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/admin/auth/google/token', ['credential' => $this->googleIdToken('LANDLORD@rentflow.test'), 'device_name' => 'tests'])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->json('token');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/user', ['Authorization' => "Bearer {$token}"])->assertOk()->assertJsonPath('user.email', 'landlord@rentflow.test');
    }

    public function test_google_never_creates_an_account(): void
    {
        $this->postJson('/api/admin/auth/google', ['credential' => $this->googleIdToken('stranger@gmail.com')], self::ADMIN_SPA)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credential');
        $this->assertGuest('web');
    }

    public function test_tokens_not_meant_for_rentflow_are_rejected(): void
    {
        foreach ([
            'another app' => ['aud' => 'someone-elses-client-id'],
            'expired' => ['exp' => time() - 60, 'iat' => time() - 3700],
            'unverified email' => ['email_verified' => false],
            'not google' => ['iss' => 'https://evil.example'],
        ] as $case => $claims) {
            $response = $this->postJson('/api/admin/auth/google', ['credential' => $this->googleIdToken('landlord@rentflow.test', $claims)], self::ADMIN_SPA);
            $this->assertSame(422, $response->status(), "{$case} token was accepted");
            $response->assertJsonValidationErrors('credential');
        }

        // A token signed by some other key (not Google's) is rejected too.
        $this->fakeGoogle();
        $forged = $this->googleIdToken('landlord@rentflow.test');
        $this->fakeGoogle(); // Google "rotates" to a key the forger doesn't have
        $this->app['cache']->flush();
        $this->postJson('/api/admin/auth/google', ['credential' => $forged], self::ADMIN_SPA)->assertUnprocessable();

        $this->assertGuest('web');
    }

    public function test_a_suspended_account_cannot_use_google_either(): void
    {
        Account::whereKey($this->kims->id)->update(['status' => 'suspended']);

        $this->postJson('/api/admin/auth/google', ['credential' => $this->googleIdToken('landlord@rentflow.test')], self::ADMIN_SPA)->assertForbidden();
    }

    public function test_a_tenant_logs_in_with_google_and_chooses_a_home(): void
    {
        $this->postJson('/api/portal/auth/google', ['credential' => $this->googleIdToken('jane@rentflow.test')], self::PORTAL_SPA)
            ->assertOk()
            ->assertJsonPath('choose_home', false)
            ->assertJsonPath('tenant.full_name', 'Jane Wanjiru');
        $this->assertAuthenticated('tenant');

        $account = Account::factory()->create(['name' => 'Mwangi Homes', 'slug' => 'mwangi-homes']);
        $other = Tenant::factory()->create(['account_id' => $account->id, 'email' => 'jane@rentflow.test']);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/portal/auth/google/token', ['credential' => $this->googleIdToken('jane@rentflow.test')])
            ->assertOk()
            ->assertJsonPath('choose_home', true)
            ->assertJsonCount(2, 'homes')
            ->assertJsonMissingPath('token');

        $token = $this->postJson('/api/portal/auth/google/token', ['credential' => $this->googleIdToken('jane@rentflow.test'), 'tenant_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('tenant.account.name', 'Mwangi Homes')
            ->json('token');

        // The app has no cookie session, only the token (the browser login above used a session).
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/portal/user', ['Authorization' => "Bearer {$token}"])->assertOk()->assertJsonPath('tenant.id', $other->id);
    }

    public function test_an_invited_tenant_must_activate_before_using_google(): void
    {
        $this->asKims(fn () => Tenant::factory()->invited()->create(['account_id' => $this->kims->id, 'email' => 'new@rentflow.test']));

        $this->postJson('/api/portal/auth/google', ['credential' => $this->googleIdToken('new@rentflow.test')], self::PORTAL_SPA)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credential');
    }
}
