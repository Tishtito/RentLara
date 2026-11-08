<?php

namespace App\Listeners;

use App\Events\TenantPasswordResetIssued;
use App\Mail\TenantPasswordResetMail;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/** Emails the password reset link (queued; needs a queue worker, see docs/SETUP.md). */
class SendTenantPasswordReset implements ShouldQueue
{
    public function __construct(private CurrentAccount $context) {}

    public function handle(TenantPasswordResetIssued $event): void
    {
        $this->context->actingAs($event->token->account_id, function () use ($event) {
            $tenant = $event->token->tenant()->with(['account', 'activeLease.unit.property'])->first();

            if (! $tenant?->email) {
                return;
            }

            $unit = $tenant->activeLease?->unit;
            $home = $unit ? "House {$unit->unit_number}, {$unit->property->name}" : null;
            $link = rtrim((string) config('app.portal_url'), '/').'/reset-password/'.$event->rawToken;

            Mail::to($tenant->email, trim("{$tenant->first_name} {$tenant->last_name}"))
                ->send(new TenantPasswordResetMail($tenant, $tenant->account, $link, $home, $event->token->expires_at));
        });
    }
}
