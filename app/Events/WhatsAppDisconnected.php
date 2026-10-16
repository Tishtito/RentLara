<?php

namespace App\Events;

use App\Models\WhatsAppConnection;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A landlord stopped sending bills on WhatsApp. */
class WhatsAppDisconnected implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WhatsAppConnection $connection,
    ) {}
}
