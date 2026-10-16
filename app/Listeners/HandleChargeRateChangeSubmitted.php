<?php

namespace App\Listeners;

use App\Events\Commands\ChargeRateChangeSubmitted;
use App\Services\Rates\ChargeRateService;

/** Runs synchronously so validation errors (e.g. a start date too early) still reach the client. */
class HandleChargeRateChangeSubmitted
{
    public function __construct(private ChargeRateService $rates) {}

    public function handle(ChargeRateChangeSubmitted $event): void
    {
        $this->rates->change($event->data);
    }
}
