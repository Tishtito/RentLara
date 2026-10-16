<?php

namespace App\Data;

/** A landlord account's details and billing settings, as a platform admin edits them. */
final readonly class AccountProfileData
{
    public function __construct(
        public int $accountId,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public string $timezone,
        public int $billingDay,
        public int $paymentDueDays,
        public string $invoicePrefix,
    ) {}
}
