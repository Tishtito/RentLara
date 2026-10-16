<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Lease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\Concerns\FakesWhatsApp;
use Tests\TestCase;

/** Every accepted write and every login lands in the activity log, secrets redacted. */
class AuditLogTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, FakesWhatsApp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
    }

    public function test_a_landlord_write_is_logged_with_who_where_and_what(): void
    {
        $owner = $this->actingAsAdmin();
        $lease = $this->asKims(fn () => Lease::firstOrFail());

        $this->postJson('/api/admin/payments', ['lease_id' => $lease->id, 'amount' => '5000', 'method' => 'cash', 'paid_at' => '2026-10-01 05:00:00'])->assertAccepted();

        $entry = AuditLog::where('action', 'payment.submitted')->sole();
        $this->assertSame($this->kims->id, $entry->account_id);
        $this->assertSame(['user', $owner->id, 'Kim Landlord', false], [$entry->actor_type, $entry->actor_id, $entry->actor_name, $entry->via_platform]);
        $this->assertSame('Recorded a payment', $entry->summary);
        $this->assertSame(['lease', $lease->id], [$entry->subject_type, $entry->subject_id]);
        $this->assertSame('5000.00', $entry->data['data']['amount'] ?? null);
    }

    public function test_secrets_are_redacted_and_refused_writes_are_not_logged(): void
    {
        $this->actingAsAdmin();
        $this->fakeWhatsApp();

        $this->putJson('/api/admin/whatsapp', [
            'phone_number_id' => '106540352242922', 'business_account_id' => '102290129340398',
            'access_token' => 'EAAG-super-secret', 'app_secret' => 'shh-app-secret',
        ])->assertAccepted();

        $entry = AuditLog::where('action', 'whats_app_connection.submitted')->sole();
        $this->assertStringNotContainsString('EAAG-super-secret', json_encode($entry->data));
        $this->assertStringNotContainsString('shh-app-secret', json_encode($entry->data));
        $this->assertSame('[redacted]', $entry->data['data']['accessToken']);

        $before = AuditLog::count();
        $this->postJson('/api/admin/roles', ['name' => 'Owner', 'permissions' => []])->assertUnprocessable();
        $this->assertSame($before, AuditLog::count());
    }

    public function test_super_admin_actions_inside_an_account_are_flagged(): void
    {
        $admin = User::factory()->superAdmin()->create(['name' => 'Platform Pat']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/roles', ['name' => 'Support', 'permissions' => ['tenants.view']], ['X-Account-Id' => (string) $this->kims->id])->assertAccepted();

        $entry = AuditLog::where('action', 'role.submitted')->sole();
        $this->assertTrue($entry->via_platform);
        $this->assertSame($this->kims->id, $entry->account_id);
        $this->assertSame('Platform Pat', $entry->actor_name);
    }

    public function test_scheduled_billing_is_system_and_logins_are_logged(): void
    {
        $this->runBilling();
        $run = AuditLog::where('action', 'billing_run.requested')->sole();
        $this->assertSame(['system', null], [$run->actor_type, $run->actor_id]);
        $this->assertSame($this->kims->id, $run->account_id);

        $this->postJson('/api/admin/auth/login', ['email' => 'landlord@rentflow.test', 'password' => 'password'], ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/login'])->assertOk();
        $login = AuditLog::where('action', 'auth.login')->sole();
        $this->assertSame(['user', 'Kim Landlord', $this->kims->id], [$login->actor_type, $login->actor_name, $login->account_id]);
        $this->assertNotNull($login->ip);
    }
}
