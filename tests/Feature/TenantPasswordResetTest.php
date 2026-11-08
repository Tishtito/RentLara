<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Mail\TenantPasswordResetMail;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\TenantAuthToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\TestCase;

/** Tenant "forgot password" links, and the landlord setting a tenant's password or sending them a link. */
class TenantPasswordResetTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, RefreshDatabase;

    private const SPA = ['Origin' => 'http://localhost:5174', 'Referer' => 'http://localhost:5174/reset-password'];

    private Tenant $jane;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        Mail::fake();
        $this->jane = $this->asKims(fn () => Tenant::where('email', 'jane@rentflow.test')->firstOrFail());
    }

    public function test_forgot_password_answers_the_same_and_emails_only_real_logins(): void
    {
        $this->postJson('/api/portal/auth/forgot-password', ['identifier' => 'nobody@example.com'])
            ->assertAccepted()->assertJsonPath('message', 'If this login exists, we\'ve emailed a link to the email address on file.');
        Mail::assertNothingSent();

        // National ID works too; the link goes to the email on file.
        $this->postJson('/api/portal/auth/forgot-password', ['identifier' => '12345678'])->assertAccepted();
        Mail::assertSent(TenantPasswordResetMail::class, fn (TenantPasswordResetMail $m) => $m->hasTo('jane@rentflow.test')
            && str_starts_with($m->link, 'http://localhost:5174/reset-password/')
            && $m->account->name === 'Kims Properties');

        Mail::assertSent(TenantPasswordResetMail::class, function (TenantPasswordResetMail $m) {
            $html = $m->render();
            $this->assertStringContainsString('Hi Jane', $html);
            $this->assertStringContainsString('expires in 60 minutes', $html);
            $this->assertStringContainsString(e($m->link), $html);

            return true;
        });

        $this->postJson('/api/portal/auth/forgot-password', [])->assertUnprocessable()->assertJsonValidationErrors('identifier');
    }

    public function test_one_link_per_landlord(): void
    {
        $other = Account::factory()->create(['name' => 'Other Homes']);
        Tenant::factory()->create(['account_id' => $other->id, 'email' => 'jane@rentflow.test', 'first_name' => 'Jane']);

        $this->postJson('/api/portal/auth/forgot-password', ['identifier' => 'Jane@Rentflow.test'])->assertAccepted();

        Mail::assertSent(TenantPasswordResetMail::class, 2);
        Mail::assertSent(TenantPasswordResetMail::class, fn (TenantPasswordResetMail $m) => $m->account->name === 'Other Homes');
        Mail::assertSent(TenantPasswordResetMail::class, fn (TenantPasswordResetMail $m) => $m->account->name === 'Kims Properties');
    }

    public function test_no_link_without_a_login_an_email_or_an_active_landlord(): void
    {
        $suspended = Account::factory()->create(['status' => 'suspended']);
        Tenant::factory()->create(['account_id' => $suspended->id, 'email' => 'sus@example.com']);
        Tenant::factory()->invited()->create(['email' => 'invited@example.com']);
        Tenant::factory()->create(['email' => null, 'national_id' => '99887766']);

        foreach (['sus@example.com', 'invited@example.com', '99887766'] as $identifier) {
            $this->postJson('/api/portal/auth/forgot-password', ['identifier' => $identifier])->assertAccepted();
        }

        Mail::assertNothingSent();
    }

    public function test_tenant_resets_the_password_from_the_link(): void
    {
        $appToken = $this->jane->createToken('Old phone', ['portal']);
        $token = $this->requestLink();
        $this->assertSame(1, $this->unusedLinks());

        $this->getJson("/api/portal/password-reset/{$token}")
            ->assertOk()
            ->assertJsonPath('first_name', 'Jane')
            ->assertJsonPath('landlord', 'Kims Properties')
            ->assertJsonPath('home', 'House 7-7, Kims Apartments')
            ->assertJsonPath('log_in_with', ['jane@rentflow.test', 'ID 12345678']);

        $this->postJson("/api/portal/password-reset/{$token}", ['password' => 'short', 'password_confirmation' => 'short'], self::SPA)
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson("/api/portal/password-reset/{$token}", ['password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'], self::SPA)
            ->assertOk()->assertJsonPath('tenant.full_name', 'Jane Wanjiru');
        $this->assertAuthenticated('tenant');

        // Works once; old app logins are gone; the new password logs in.
        $this->getJson("/api/portal/password-reset/{$token}")->assertNotFound();
        $this->postJson("/api/portal/password-reset/{$token}", ['password' => 'another-pass-1', 'password_confirmation' => 'another-pass-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertNull(PersonalAccessToken::find($appToken->accessToken->id));

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->travel(2)->minutes(); // plain throttle:6,1 shares one bucket per IP across routes
        $this->postJson('/api/portal/auth/login', ['identifier' => 'jane@rentflow.test', 'password' => 'password'], self::SPA)->assertUnprocessable();
        $this->postJson('/api/portal/auth/login', ['identifier' => 'jane@rentflow.test', 'password' => 'brand-new-pass'], self::SPA)->assertOk();
    }

    public function test_reset_from_the_app_returns_a_token(): void
    {
        $token = $this->requestLink();

        $this->postJson("/api/portal/password-reset/{$token}", ['password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass', 'device_name' => 'Pixel 8'])
            ->assertOk()->assertJsonPath('token_type', 'Bearer')->assertJsonStructure(['token', 'tenant']);
    }

    public function test_links_expire_and_only_the_newest_works(): void
    {
        $first = $this->requestLink();
        $second = $this->requestLink();

        $this->getJson("/api/portal/password-reset/{$first}")->assertNotFound();
        $this->getJson("/api/portal/password-reset/{$second}")->assertOk();
        $this->getJson('/api/portal/password-reset/'.Str::random(64))->assertNotFound();

        $this->travel(61)->minutes();
        $this->getJson("/api/portal/password-reset/{$second}")->assertNotFound();
    }

    public function test_landlord_sets_a_tenants_password(): void
    {
        $this->requestLink();
        $appToken = $this->jane->createToken('Old phone', ['portal']);
        $this->actingAsAdmin();

        $this->putJson("/api/admin/tenants/{$this->jane->id}/password", ['password' => 'given-by-landlord', 'password_confirmation' => 'nope'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson("/api/admin/tenants/{$this->jane->id}/password", ['password' => 'given-by-landlord', 'password_confirmation' => 'given-by-landlord'])
            ->assertAccepted();

        $this->assertSame(0, $this->unusedLinks()); // the emailed link stops working
        $this->assertNull(PersonalAccessToken::find($appToken->accessToken->id));

        $log = AuditLog::where('action', 'tenant_password_set.submitted')->firstOrFail();
        $this->assertSame('Set a tenant\'s password', $log->summary);
        $this->assertStringNotContainsString('given-by-landlord', json_encode($log->getAttributes()));

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/portal/auth/token', ['identifier' => '12345678', 'password' => 'given-by-landlord', 'device_name' => 'test'])->assertOk();
    }

    public function test_landlord_emails_a_reset_link(): void
    {
        $this->actingAsAdmin();

        $this->postJson("/api/admin/tenants/{$this->jane->id}/password-reset")
            ->assertAccepted()
            ->assertJsonPath('messages.0.channel', 'email')
            ->assertJsonPath('messages.0.to', 'jane@rentflow.test')
            ->assertJsonPath('messages.0.sending', true);

        Mail::assertSent(TenantPasswordResetMail::class, function (TenantPasswordResetMail $m) {
            $this->assertStringContainsString('expires in 24 hours', $m->render());

            return $m->hasTo('jane@rentflow.test');
        });
    }

    public function test_invited_tenants_get_the_invitation_instead(): void
    {
        $invited = $this->asKims(fn () => Tenant::factory()->invited()->create(['account_id' => $this->kims->id]));
        $this->actingAsAdmin();

        $this->putJson("/api/admin/tenants/{$invited->id}/password", ['password' => 'given-by-landlord', 'password_confirmation' => 'given-by-landlord'])
            ->assertUnprocessable()->assertJsonValidationErrors('tenant');
        $this->postJson("/api/admin/tenants/{$invited->id}/password-reset")->assertUnprocessable()->assertJsonValidationErrors('tenant');
        $this->assertSame(TenantStatus::Invited, $invited->fresh()->status);
    }

    public function test_only_tenant_managers_in_this_account(): void
    {
        $stranger = Tenant::factory()->create();

        $this->actingAsAdmin('caretaker@rentflow.test');
        $this->putJson("/api/admin/tenants/{$this->jane->id}/password", ['password' => 'given-by-landlord', 'password_confirmation' => 'given-by-landlord'])
            ->assertForbidden();
        $this->postJson("/api/admin/tenants/{$this->jane->id}/password-reset")->assertForbidden();

        $this->actingAsAdmin();
        $this->putJson("/api/admin/tenants/{$stranger->id}/password", ['password' => 'given-by-landlord', 'password_confirmation' => 'given-by-landlord'])
            ->assertNotFound();
    }

    /** Ask for a reset link for Jane and return the raw token from the email. */
    private function requestLink(): string
    {
        $this->postJson('/api/portal/auth/forgot-password', ['identifier' => 'jane@rentflow.test'])->assertAccepted();

        $link = null;
        Mail::assertSent(TenantPasswordResetMail::class, function (TenantPasswordResetMail $m) use (&$link) {
            $link = $m->link;

            return true;
        });

        return Str::afterLast($link, '/reset-password/');
    }

    private function unusedLinks(): int
    {
        return $this->asKims(fn () => TenantAuthToken::where('tenant_id', $this->jane->id)
            ->where('purpose', TenantAuthToken::PURPOSE_PASSWORD_RESET)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->count());
    }
}
