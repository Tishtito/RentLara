<?php

namespace App\Events\Commands;

use App\Data\RecordPaymentData;
use Illuminate\Foundation\Events\Dispatchable;

/** Intent: someone submitted a payment to record. Handled by HandlePaymentSubmitted. */
class PaymentSubmitted
{
    use Dispatchable;

    public function __construct(public readonly RecordPaymentData $data) {}
}
