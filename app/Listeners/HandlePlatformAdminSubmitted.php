<?php

namespace App\Listeners;

use App\Events\Commands\PlatformAdminSubmitted;
use App\Services\Platform\PlatformAdminService;

class HandlePlatformAdminSubmitted
{
    public function __construct(private PlatformAdminService $service) {}

    public function handle(PlatformAdminSubmitted $event): void
    {
        $this->service->invite($event->name, $event->email);
    }
}
