<?php

namespace App\Listeners;

use App\Events\Commands\StaffStatusRequested;
use App\Services\Team\TeamService;

class HandleStaffStatusRequested
{
    public function __construct(private TeamService $team) {}

    public function handle(StaffStatusRequested $event): void
    {
        match ($event->action) {
            'removed' => $this->team->remove($event->accountId, $event->userId, $event->actorId),
            'reinvite' => $this->team->resendInvitation($event->accountId, $event->userId),
            default => $this->team->setStatus($event->accountId, $event->userId, $event->action, $event->actorId),
        };
    }
}
