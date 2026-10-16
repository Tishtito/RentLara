<?php

namespace App\Listeners;

use App\Events\Commands\BillingRunRequested;
use App\Services\Billing\BillingService;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Queued: a run can bill hundreds of leases. One job per account. */
class HandleBillingRunRequested implements ShouldQueue
{
    public int $tries = 1;

    public function __construct(private BillingService $billing) {}

    public function handle(BillingRunRequested $event): void
    {
        $this->billing->run($event->data);
    }
}
