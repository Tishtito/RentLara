<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Enums\UnitStatus;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\Concerns\FakesWhatsApp;
use Tests\TestCase;

/** Send actions return `messages`: each channel, where it goes, or why it was skipped (for the admin alerts). */
class SendAlertsTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, FakesWhatsApp, RefreshDatabase;

    private Tenant $jane;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        Mail::fake();
        $this->jane = $this->asKims(function () {
            $jane = Tenant::where('email', 'jane@rentflow.test')->firstOrFail();
            $jane->forceFill(['status' => TenantStatus::Invited])->save();

            return $jane;
        });
        $this->actingAsAdmin();
    }

    public function test_invitation_lists_email_and_whatsapp(): void
    {
        $this->connectWhatsApp($this->kims);
        $this->fakeWhatsApp();

        $this->postJson("/api/admin/tenants/{$this->jane->id}/invitation")->assertAccepted()
            ->assertJsonPath('messages', [
                ['channel' => 'email', 'to' => 'jane@rentflow.test', 'sending' => true, 'note' => null],
                ['channel' => 'whatsapp', 'to' => '+254712345678', 'sending' => true, 'note' => null],
            ]);
    }

    public function test_invitation_says_why_whatsapp_was_skipped(): void
    {
        $invite = fn () => $this->postJson("/api/admin/tenants/{$this->jane->id}/invitation")->assertAccepted();

        $invite()->assertJsonPath('messages.0.sending', true)
            ->assertJsonPath('messages.1.sending', false)
            ->assertJsonPath('messages.1.note', "WhatsApp isn't connected (Settings → WhatsApp).");

        $this->connectWhatsApp($this->kims);
        $this->fakeWhatsApp();
        $this->asKims(fn () => $this->jane->forceFill(['notification_channels' => ['email']])->save());
        $invite()->assertJsonPath('messages.1.note', "The tenant doesn't get WhatsApp messages.");

        $this->asKims(fn () => $this->jane->forceFill(['notification_channels' => null, 'phone' => '', 'whatsapp_phone' => null])->save());
        $invite()->assertJsonPath('messages.1.note', 'Tenant has no phone number.');
    }

    public function test_bill_resend_lists_the_chosen_or_usual_channels(): void
    {
        $this->connectWhatsApp($this->kims);
        $this->fakeWhatsApp();
        $this->runBilling();
        $invoice = $this->asKims(fn () => Invoice::firstOrFail());

        $this->postJson("/api/admin/invoices/{$invoice->id}/resend")->assertAccepted()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.1.to', '+254712345678');

        $this->postJson("/api/admin/invoices/{$invoice->id}/resend", ['channels' => ['email']])->assertAccepted()
            ->assertJsonPath('messages', [['channel' => 'email', 'to' => 'jane@rentflow.test', 'sending' => true, 'note' => null]]);
    }

    public function test_payment_lists_where_the_receipt_goes(): void
    {
        $this->runBilling();
        $invoice = $this->asKims(fn () => Invoice::firstOrFail());

        $this->postJson('/api/admin/payments', [
            'lease_id' => $invoice->lease_id, 'amount' => '5000', 'method' => 'cash', 'paid_at' => now()->toDateTimeString(),
        ])->assertAccepted()
            ->assertJsonPath('messages.0', ['channel' => 'email', 'to' => 'jane@rentflow.test', 'sending' => true, 'note' => null])
            ->assertJsonPath('messages.1.sending', false);
    }

    public function test_move_in_lists_the_invitation_only_with_an_email(): void
    {
        $units = $this->asKims(fn () => Unit::factory()->count(2)->sequence(['unit_number' => '7-8'], ['unit_number' => '7-9'])
            ->create(['property_id' => Property::firstOrFail()->id, 'status' => UnitStatus::Vacant]));
        $moveIn = fn (Unit $unit, array $tenant) => $this->postJson("/api/admin/units/{$unit->id}/move-in", [
            'tenant' => $tenant + ['first_name' => 'Peter', 'last_name' => 'Mwangi', 'phone' => '0722 000 481'],
            'lease' => ['monthly_rent' => '15000', 'deposit_amount' => '15000', 'start_date' => '2026-10-01'],
        ])->assertAccepted();

        $moveIn($units[0], ['email' => 'peter@example.com'])
            ->assertJsonPath('messages.0.to', 'peter@example.com')
            ->assertJsonPath('messages.1.channel', 'whatsapp');
        $moveIn($units[1], [])->assertJsonPath('messages', []);
    }

    public function test_platform_resend_lists_the_channel_and_counts(): void
    {
        $this->connectWhatsApp($this->kims);
        $this->fakeWhatsApp();
        $this->runBilling();
        DB::table('notification_deliveries')->update(['status' => 'failed', 'error' => 'Down']);
        $email = DB::table('notification_deliveries')->where('channel', 'email')->value('id');
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson("/api/platform/deliveries/{$email}/retry")->assertAccepted()
            ->assertJsonPath('messages', [['channel' => 'email', 'to' => 'jane@rentflow.test', 'sending' => true, 'note' => null]]);

        $this->postJson('/api/platform/deliveries/retry-failed')->assertAccepted()
            ->assertJsonPath('messages', [['channel' => 'whatsapp', 'count' => 1, 'sending' => true]]);
    }
}
