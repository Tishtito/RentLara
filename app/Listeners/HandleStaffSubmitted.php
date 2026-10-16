<?php

namespace App\Listeners;

use App\Events\Commands\StaffSubmitted;
use App\Services\Team\TeamService;

/** Runs synchronously so the guards' validation errors (last Owner, own roles) still reach the client. */
class HandleStaffSubmitted
{
    public function __construct(private TeamService $team) {}

    public function handle(StaffSubmitted $event): void
    {
        $event->data->userId ? $this->team->update($event->data) : $this->team->invite($event->data);
    }
}
