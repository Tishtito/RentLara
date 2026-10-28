<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Events\NotificationQueued;
use App\Mail\TenantInvitationMail;
use App\Models\NotificationDelivery;
use App\Models\Tenant;
use App\Services\Notifications\NotificationSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\Concerns\FakesWhatsApp;
use Tests\TestCase;

/** Tenant invitation → email + WhatsApp template with a "Set up my login" button and app links. */
class WhatsAppInvitationTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, FakesWhatsApp, RefreshDatabase;

    private Tenant $jane;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedKims();
        Mail::fake();
        config(['app.tenant_app.android_url' => 'https://play.google.com/store/apps/details?id=app.rentflow.portal']);

        // Jane hasn't set up her login yet.
        $this->jane = $this->asKims(function () {
            $jane = Tenant::where('email', 'jane@rentflow.test')->firstOrFail();
            $jane->forceFill(['status' => TenantStatus::Invited])->save();

            return $jane;
        });
        $this->actingAsAdmin();
    }

    public function test_invitation_goes_out_on_whatsapp_and_email(): void
    {
        $this->connectWhatsApp($this->kims);
        $this->fakeWhatsApp();

        $this->invite()->assertAccepted();

        $token = $this->emailedToken();
        $delivery = $this->deliveries()->sole();
        $this->assertSame('tenant_invitation', $delivery->type);
        $this->assertSame('whatsapp', $delivery->channel->value);
        $this->assertSame('sent', $delivery->status);
        $this->assertSame('wamid.ABC', $delivery->provider_message_id);
        $this->assertArrayNotHasKey('payload', $delivery->toArray());

        Http::assertSent(function (Request $r) use ($token) {
            if (! str_ends_with($r->url(), '/messages')) {
                return false;
            }

            [$body, $button] = $r['template']['components'];

            return $r['to'] === '254712345678'
                && $r['template']['name'] === 'rentflow_tenant_invitation'
                && array_column($body['parameters'], 'text') === [
                    'Jane', 'Kims Properties', 'House 7-7, Kims Apartments',
                    'Android: https://play.google.com/store/apps/details?id=app.rentflow.portal',
                ]
                && $button['type'] === 'button' && $button['sub_type'] === 'url' && $button['index'] === '0'
                && $button['parameters'][0]['text'] === $token;
        });

        // The email mentions the app too.
        Mail::assertSent(TenantInvitationMail::class, fn (TenantInvitationMail $m) => str_contains($m->render(), 'play.google.com'));
    }

    public function test_landlord_without_whatsapp_or_tenant_without_whatsapp_only_emails(): void
    {
        Http::fake();
        $this->invite()->assertAccepted();
        $this->assertCount(0, $this->deliveries());

        $this->connectWhatsApp($this->kims);
        $this->asKims(fn () => $this->jane->forceFill(['notification_channels' => ['email']])->save());
        $this->invite()->assertAccepted();

        $this->assertCount(0, $this->deliveries());
        Http::assertNothingSent();
        Mail::assertSent(TenantInvitationMail::class, 2);
    }

    public function test_tenant_without_a_phone_gets_a_failed_delivery(): void
    {
        $this->connectWhatsApp($this->kims);
        $this->fakeWhatsApp();
        $this->asKims(fn () => Tenant::query()->update(['phone' => '', 'whatsapp_phone' => null]));

        $this->invite()->assertAccepted();

        $delivery = $this->deliveries()->sole();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame('Tenant has no phone number.', $delivery->error);
    }

    public function test_an_old_link_is_never_sent(): void
    {
        $this->connectWhatsApp($this->kims);
        $this->fakeWhatsApp();
        Event::fake([NotificationQueued::class]); // send by hand

        $this->invite()->assertAccepted();
        $first = $this->deliveries()->sole();
        $this->invite()->assertAccepted(); // a new link; the first one stops working

        $this->asKims(fn () => app(NotificationSender::class)->send($first));

        $this->assertSame('failed', $first->fresh()->status);
        $this->assertStringContainsString('expired or was already used', $first->fresh()->error);
        Http::assertNothingSent();
    }

    public function test_landlord_can_use_their_own_template_name(): void
    {
        $this->connectWhatsApp($this->kims, ['invitation_template_name' => 'kims_karibu']);
        $this->fakeWhatsApp();

        $this->getJson('/api/admin/whatsapp')->assertOk()->assertJsonPath('data.invitation_template_name', 'kims_karibu');
        $this->invite()->assertAccepted();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['template']['name'] === 'kims_karibu');
    }

    private function invite()
    {
        return $this->postJson("/api/admin/tenants/{$this->jane->id}/invitation");
    }

    private function emailedToken(): string
    {
        $link = null;
        Mail::assertSent(TenantInvitationMail::class, function (TenantInvitationMail $m) use (&$link) {
            $link = $m->link;

            return true;
        });

        return Str::afterLast($link, '/invite/');
    }

    private function deliveries()
    {
        return $this->asKims(fn () => NotificationDelivery::orderBy('id')->get());
    }
}
