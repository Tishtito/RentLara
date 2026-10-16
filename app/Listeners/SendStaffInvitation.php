<?php

namespace App\Listeners;

use App\Events\StaffInvited;
use App\Services\Auth\AdminPasswordService;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Emails a new team member their "set up your login" link (queued; needs a queue worker). */
class SendStaffInvitation implements ShouldQueue
{
    public function __construct(private AdminPasswordService $passwords) {}

    public function handle(StaffInvited $event): void
    {
        $this->passwords->sendInvitation($event->user);
    }
}
