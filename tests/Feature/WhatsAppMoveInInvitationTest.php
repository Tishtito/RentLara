<?php

namespace Tests\Feature;

use App\Enums\UnitStatus;
use App\Mail\TenantInvitationMail;
use App\Models\Meter;
use App\Models\NotificationDelivery;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\BillsKimsProperties;
use Tests\Concerns\FakesWhatsApp;
use Tests\TestCase;

/** Adding a tenant (move-in) invites them by email AND WhatsApp. */
class WhatsAppMoveInInvitationTest extends TestCase
{
    use ActsAsAdmin, BillsKimsProperties, FakesWhatsApp, RefreshDatabase;

    public function test_move_in_invites_by_email_and_whatsapp(): void
    {
        $this->seedKims();
        Mail::fake();
        $this->connectWhatsApp($this->kims);
        $this->fakeWhatsApp();

        [$unit, $meters] = $this->asKims(function () {
            $unit = Unit::factory()->create(['property_id' => Property::firstOrFail()->id, 'unit_number' => '7-8', 'status' => UnitStatus::Vacant]);
            $meters = [];
            foreach (['electricity' => '500', 'water' => '40'] as $code => $initial) {
                $meters[$code] = Meter::create(['unit_id' => $unit->id, 'charge_code' => $code, 'initial_reading' => $initial, 'is_active' => true]);
            }

            return [$unit, $meters];
        });

        $this->actingAsAdmin();
        $this->postJson("/api/admin/units/{$unit->id}/move-in", [
            'tenant' => ['first_name' => 'Peter', 'last_name' => 'Mwangi', 'phone' => '0722 000 481', 'email' => 'peter@example.com'],
            'lease' => ['monthly_rent' => '15000', 'deposit_amount' => '15000', 'start_date' => '2026-10-01'],
            'opening_readings' => [$meters['electricity']->id => '520', $meters['water']->id => '41'],
        ])->assertAccepted();

        Mail::assertSent(TenantInvitationMail::class, 1);
        $delivery = $this->asKims(fn () => NotificationDelivery::where('type', 'tenant_invitation')->sole());
        $this->assertSame('whatsapp', $delivery->channel->value);
        $this->assertSame('sent', $delivery->status, (string) $delivery->error);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === '254722000481'
            && $r['template']['name'] === 'rentflow_tenant_invitation');
    }
}
