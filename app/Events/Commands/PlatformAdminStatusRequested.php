<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent (platform): suspend / reactivate / remove a platform admin, or resend their invitation. Handled by HandlePlatformAdminStatusRequested. */
class PlatformAdminStatusRequested
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        /** active | suspended | removed | reinvite */
        public readonly string $action,
        public readonly ?int $actorId = null,
    ) {}
}
