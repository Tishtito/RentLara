<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent (platform): invite another platform admin. Handled by HandlePlatformAdminSubmitted. */
class PlatformAdminSubmitted
{
    use Dispatchable;

    public function __construct(
        public readonly string $name,
        public readonly string $email,
    ) {}
}
