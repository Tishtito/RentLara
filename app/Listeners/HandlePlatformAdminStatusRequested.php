<?php

namespace App\Listeners;

use App\Events\Commands\PlatformAdminStatusRequested;
use App\Services\Platform\PlatformAdminService;

class HandlePlatformAdminStatusRequested
{
    public function __construct(private PlatformAdminService $service) {}

    public function handle(PlatformAdminStatusRequested $event): void
    {
        $this->service->change($event->userId, $event->action, $event->actorId);
    }
}
