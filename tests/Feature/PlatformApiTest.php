<?php

namespace Tests\Feature;

use App\Mail\AdminPasswordLinkMail;
use App\Mail\InvoiceIssuedMail;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BillingRun;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BillsKimsProperties;
use Tests\Concerns\FakesWhatsApp;
use Tests\TestCase;

/** The super admin side: overview, landlord accounts, billing, messaging, platform admins, activity log. */
class PlatformApiTest extends TestCase
{
    use BillsKimsProperties, FakesWhatsApp, RefreshDatabase;

    private const SPA = ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/login'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        Mail::fake();
        $this->admin = User::factory()->superAdmin()->create(['name' => 'Platform Pat', 'email' => 'pat@rentflow.test']);
    }

    public function test_every_platform_route_is_super_admin_only(): void
    {
        $routes = collect(Route::getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/platform'));
        $this->assertGreaterThan(20, $routes->count());

        Sanctum::actingAs(User::where('email', 'landlord@rentflow.test')->firstOrFail());
        foreach ($routes as $route) {
            $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $this->json($route->methods()[0], $uri)->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/portal/auth/token', ['identifier' => 'jane@rentflow.test', 'password' => 'password'])->json('token');
        $this->getJson('/api/platform/overview', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_overview_and_account_list_and_detail(): void
    {
        $this->runBilling();
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/platform/overview')->assertOk()
            ->assertJsonPath('data.accounts.active', 1)
            ->assertJsonPath('data.units.occupied', 1)
            ->assertJsonPath('data.month.bills_count', 1)
            ->assertJsonPath('data.month.billed', '18500.00')
            ->assertJsonPath('data.balance_outstanding', '19500.00');

        $this->getJson('/api/platform/accounts?search=Kim')->assertOk()
            ->assertJsonPath('data.0.owner_name', 'Kim Landlord')
            ->assertJsonPath('data.0.last_billing_run.invoices_created', 1)
            ->assertJsonPath('meta.total', 1);
        $this->getJson('/api/platform/accounts?status=suspended')->assertJsonPath('meta.total', 0);

        $this->getJson("/api/platform/accounts/{$this->kims->id}")->assertOk()
            ->assertJsonPath('data.counts.units', 1)
            ->assertJsonPath('data.balance_outstanding', '19500.00')
            ->assertJsonPath('data.billing_runs.0.invoices_created', 1)
            ->assertJsonCount(2, 'data.team');
    }

    public function test_create_account_invites_the_owner_who_lands_in_an_empty_account(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/platform/accounts', ['business_name' => 'Ochieng Rentals', 'owner_name' => 'Tom Ochieng', 'owner_email' => 'tom@example.com', 'owner_phone' => '0733 111 917'])->assertAccepted();
        $this->postJson('/api/platform/accounts', ['business_name' => 'Dup', 'owner_name' => 'X', 'owner_email' => 'landlord@rentflow.test'])->assertUnprocessable()->assertJsonValidationErrors('owner_email');

        $account = Account::where('slug', 'ochieng-rentals')->firstOrFail();
        $this->assertSame('tom@example.com', $account->email);

        $link = null;
        Mail::assertSent(AdminPasswordLinkMail::class, function (AdminPasswordLinkMail $m) use (&$link) {
            $link = $m->link;

            return $m->hasTo('tom@example.com') && str_contains($m->render(), 'Ochieng Rentals');
        });
        parse_str(parse_url($link, PHP_URL_QUERY), $q);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/admin/auth/set-password', ['email' => $q['email'], 'token' => $q['token'], 'password' => 'owner-pass-1', 'password_confirmation' => 'owner-pass-1'], self::SPA)
            ->assertOk()->assertJsonPath('user.roles', ['Owner'])->assertJsonPath('user.account_id', $account->id);
        $this->getJson('/api/admin/properties')->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame('account_creation.submitted', AuditLog::where('via_platform', true)->value('action'));
    }

    public function test_edit_and_suspend_and_reactivate_an_account(): void
    {
        $owner = User::where('email', 'landlord@rentflow.test')->firstOrFail();
        $owner->createToken('app', ['admin']);
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/platform/accounts/{$this->kims->id}", [
            'name' => 'Kims Properties Ltd', 'email' => 'office@kims.test', 'phone' => '0711 000 204', 'timezone' => 'Africa/Nairobi',
            'billing_day' => 5, 'payment_due_days' => 7, 'invoice_prefix' => 'kp',
        ])->assertAccepted();
        $this->assertSame(['Kims Properties Ltd', 7, 'KP', '+254711000204'], [$this->kims->fresh()->name, $this->kims->fresh()->payment_due_days, $this->kims->fresh()->invoice_prefix, $this->kims->fresh()->phone]);

        $this->patchJson("/api/platform/accounts/{$this->kims->id}/status", ['status' => 'suspended', 'reason' => 'Unpaid subscription'])->assertAccepted();
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame('Unpaid subscription', AuditLog::where('action', 'account_status.requested')->sole()->data['reason']);

        // Nobody in the account can log in, and it isn't billed.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/admin/auth/login', ['email' => 'landlord@rentflow.test', 'password' => 'password'], self::SPA)->assertForbidden();
        $this->runBilling();
        $this->assertSame(0, BillingRun::withoutGlobalScopes()->count());

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/platform/accounts/{$this->kims->id}/billing-runs")->assertUnprocessable()->assertJsonValidationErrors('account');
        $this->patchJson("/api/platform/accounts/{$this->kims->id}/status", ['status' => 'active'])->assertAccepted();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/admin/auth/login', ['email' => 'landlord@rentflow.test', 'password' => 'password'], self::SPA)->assertOk();
    }

    public function test_billing_runs_across_accounts_and_run_now(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/platform/accounts/{$this->kims->id}/billing-runs", ['run_date' => '2026-11-05'])->assertAccepted();
        $run = BillingRun::withoutGlobalScopes()->sole();
        $this->assertSame(['manual', $this->admin->id, 1], [$run->trigger, $run->triggered_by, $run->invoices_created]);
        $this->assertSame(1950000, Money::toCents(DB::table('invoices')->value('total_due')));

        $this->getJson('/api/platform/billing-runs')->assertOk()
            ->assertJsonPath('data.0.account.name', 'Kims Properties')
            ->assertJsonPath('data.0.triggered_by', 'Platform Pat');
        $this->getJson('/api/platform/billing-runs?problems=1')->assertJsonCount(0, 'data');

        // A skipped lease shows its unit, tenant and reason.
        DB::table('billing_runs')->where('id', $run->id)->update(['leases_skipped' => 1, 'errors' => json_encode([['lease_id' => 1, 'unit_id' => 1, 'reason' => 'No reading for Electricity.']])]);
        $this->getJson("/api/platform/billing-runs/{$run->id}")->assertOk()
            ->assertJsonPath('data.skipped.0.unit', '7-7')
            ->assertJsonPath('data.skipped.0.tenant', 'Jane Wanjiru')
            ->assertJsonPath('data.skipped.0.reason', 'No reading for Electricity.');
    }

    public function test_messages_list_retry_and_whatsapp_numbers_without_secrets(): void
    {
        $this->connectWhatsApp($this->kims, ['access_token' => 'EAAG-never-show', 'app_secret' => 'never-show-secret']);
        $this->fakeWhatsApp();
        $this->runBilling();
        Mail::assertSent(InvoiceIssuedMail::class, 1);

        $email = DB::table('notification_deliveries')->where('channel', 'email')->value('id');
        DB::table('notification_deliveries')->where('id', $email)->update(['status' => 'failed', 'error' => 'SMTP connection refused']);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/platform/deliveries?status=failed')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.error', 'SMTP connection refused')
            ->assertJsonPath('data.0.account.name', 'Kims Properties');
        $this->getJson('/api/platform/deliveries/stats')->assertOk()->assertJsonPath('data.failed_total', 1);

        $this->postJson("/api/platform/deliveries/{$email}/retry")->assertAccepted();
        $this->assertSame('sent', DB::table('notification_deliveries')->where('id', $email)->value('status'));
        $this->postJson("/api/platform/deliveries/{$email}/retry")->assertUnprocessable();

        DB::table('notification_deliveries')->where('id', $email)->update(['status' => 'failed']);
        $this->postJson('/api/platform/deliveries/retry-failed', ['account_id' => $this->kims->id])->assertAccepted();
        $this->assertSame(0, DB::table('notification_deliveries')->where('status', 'failed')->count());

        $whatsapp = $this->getJson('/api/platform/whatsapp-connections')->assertOk()
            ->assertJsonPath('data.0.account_name', 'Kims Properties')
            ->assertJsonPath('data.0.status', 'active');
        $this->assertStringNotContainsString('never-show', $whatsapp->getContent());
    }

    public function test_platform_admins_invite_and_guards(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/platform/admins', ['name' => 'Second Admin', 'email' => 'second@rentflow.test'])->assertAccepted();
        $second = User::where('email', 'second@rentflow.test')->firstOrFail();
        $this->assertTrue($second->isSuperAdmin());
        Mail::assertSent(AdminPasswordLinkMail::class, fn ($m) => $m->hasTo('second@rentflow.test') && $m->invitation);

        $this->getJson('/api/platform/admins')->assertOk()->assertJsonCount(2, 'data')->assertJsonFragment(['email' => 'pat@rentflow.test', 'is_me' => true]);

        // Only one active admin (the other is invited): can't suspend or remove yourself, and someone must stay.
        $this->patchJson("/api/platform/admins/{$this->admin->id}/status", ['status' => 'suspended'])->assertUnprocessable();
        $this->deleteJson("/api/platform/admins/{$this->admin->id}")->assertUnprocessable();

        $second->forceFill(['status' => 'active'])->save();
        $this->patchJson("/api/platform/admins/{$second->id}/status", ['status' => 'suspended'])->assertAccepted();
        $this->assertSame('suspended', $second->fresh()->status);
        $this->deleteJson("/api/platform/admins/{$second->id}")->assertAccepted();
        $this->assertSoftDeleted('users', ['id' => $second->id]);

        // Landlord staff aren't platform admins.
        $staff = User::where('email', 'caretaker@rentflow.test')->firstOrFail();
        $this->deleteJson("/api/platform/admins/{$staff->id}")->assertNotFound();
    }

    public function test_activity_log_filters(): void
    {
        $this->runBilling();
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/platform/accounts/{$this->kims->id}/status", ['status' => 'active'])->assertAccepted();

        $this->getJson('/api/platform/audit-log')->assertOk()->assertJsonPath('data.0.action', 'account_status.requested')->assertJsonPath('data.0.actor_name', 'Platform Pat');
        $this->getJson('/api/platform/audit-log?action=billing_run.requested')->assertJsonCount(1, 'data')->assertJsonPath('data.0.actor_type', 'system');
        $this->getJson("/api/platform/audit-log?account_id={$this->kims->id}&q=billing")->assertJsonCount(1, 'data');
        $this->assertContains('billing_run.requested', array_column($this->getJson('/api/platform/audit-log/actions')->json('data'), 'action'));
    }
}
