<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: suspend / reactivate / remove a team member, or resend their invitation. Handled by HandleStaffStatusRequested. */
class StaffStatusRequested
{
    use Dispatchable;

    /** @param  'active'|'suspended'|'removed'|'reinvite'  $action */
    public function __construct(
        public readonly int $accountId,
        public readonly int $userId,
        public readonly string $action,
        public readonly ?int $actorId = null,
    ) {}
}
