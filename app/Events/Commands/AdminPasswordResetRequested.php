<?php

namespace App\Events\Commands;

use Illuminate\Foundation\Events\Dispatchable;

/** Intent: email a "choose a new password" link (admin portal). Handled by HandleAdminPasswordResetRequested. */
class AdminPasswordResetRequested
{
    use Dispatchable;

    public function __construct(public readonly string $email) {}
}
