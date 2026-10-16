<?php

namespace App\Listeners;

use App\Events\Commands\PaymentSubmitted;
use App\Services\Payments\PaymentService;

/** Runs synchronously so validation errors from the service still reach the client. */
class HandlePaymentSubmitted
{
    public function __construct(private PaymentService $payments) {}

    public function handle(PaymentSubmitted $event): void
    {
        $this->payments->record($event->data);
    }
}
