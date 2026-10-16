<?php

namespace App\Listeners;

use App\Events\Commands\LandlordRegistrationSubmitted;
use App\Services\Accounts\AccountRegistrationService;

/** Runs synchronously so the account exists when the sign-up page logs in right after the 202. */
class HandleLandlordRegistrationSubmitted
{
    public function __construct(private AccountRegistrationService $registrations) {}

    public function handle(LandlordRegistrationSubmitted $event): void
    {
        $this->registrations->register($event->data);
    }
}
