<?php

namespace App\Listeners;

use App\Events\TenantInvitationIssued;
use App\Services\Notifications\TenantInvitationNotificationService;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Queue\ShouldQueue;

/** A new invitation link → also send it on WhatsApp when the landlord's number is connected. */
class QueueTenantInvitationWhatsApp implements ShouldQueue
{
    public function __construct(
        private CurrentAccount $context,
        private TenantInvitationNotificationService $notifications,
    ) {}

    public function handle(TenantInvitationIssued $event): void
    {
        $this->context->actingAs(
            $event->token->account_id,
            fn () => $this->notifications->queueWhatsApp($event->token, $event->rawToken),
        );
    }
}
