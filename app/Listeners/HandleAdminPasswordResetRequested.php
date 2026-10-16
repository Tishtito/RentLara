<?php

namespace App\Listeners;

use App\Events\Commands\AdminPasswordResetRequested;
use App\Services\Auth\AdminPasswordService;

class HandleAdminPasswordResetRequested
{
    public function __construct(private AdminPasswordService $passwords) {}

    public function handle(AdminPasswordResetRequested $event): void
    {
        $this->passwords->sendReset($event->email);
    }
}
