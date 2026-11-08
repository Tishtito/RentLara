<?php

namespace App\Events;

use App\Models\TenantAuthToken;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A tenant-portal password reset link was created; $rawToken goes in the link and is never stored. */
class TenantPasswordResetIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly TenantAuthToken $token,
        public readonly string $rawToken,
    ) {}
}
