<?php

namespace App\Data;

use App\Enums\PaymentMethod;
use Carbon\CarbonImmutable;

/**
 * A payment to record against a lease. Carries its account so the handler
 * can run outside the original request (queued, M-Pesa callback).
 */
final readonly class RecordPaymentData
{
    public function __construct(
        public int $accountId,
        public int $leaseId,
        public string $amount,
        public PaymentMethod $method,
        public CarbonImmutable $paidAt,
        public ?string $reference = null,
        public ?string $payerPhone = null,
        public ?int $invoiceId = null,
        public ?int $recordedBy = null,
        public ?string $notes = null,
        public ?array $meta = null,
    ) {}
}
