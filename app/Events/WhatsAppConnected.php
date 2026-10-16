<?php

namespace App\Events;

use App\Models\WhatsAppConnection;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A landlord connected (or updated) their WhatsApp number. */
class WhatsAppConnected implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WhatsAppConnection $connection,
    ) {}
}
