<?php

namespace App\Listeners;

use App\Events\TenantInvitationIssued;
use App\Mail\TenantInvitationMail;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/** Emails the invitation link (queued; needs a queue worker, see docs/SETUP.md). */
class SendTenantInvitation implements ShouldQueue
{
    public function __construct(private CurrentAccount $context) {}

    public function handle(TenantInvitationIssued $event): void
    {
        $this->context->actingAs($event->token->account_id, function () use ($event) {
            $tenant = $event->token->tenant()->with(['account', 'activeLease.unit.property'])->first();

            if (! $tenant?->email) {
                return;
            }

            $unit = $tenant->activeLease?->unit;
            $home = $unit ? "House {$unit->unit_number}, {$unit->property->name}" : null;
            $link = rtrim((string) config('app.portal_url'), '/').'/invite/'.$event->rawToken;

            Mail::to($tenant->email, trim("{$tenant->first_name} {$tenant->last_name}"))
                ->send(new TenantInvitationMail($tenant, $tenant->account, $link, $home));
        });
    }
}
