<?php

namespace App\Data;

/** A landlord account created by a platform admin; the owner gets a "set up your login" email. */
final readonly class CreateAccountData
{
    public function __construct(
        public string $businessName,
        public ?string $email,
        public ?string $phone,
        public string $ownerName,
        public string $ownerEmail,
        public ?string $ownerPhone,
    ) {}
}
