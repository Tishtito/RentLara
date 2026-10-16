<?php

namespace App\Data;

/** A new landlord signing up from the admin portal: their account and their own login. */
final readonly class RegisterLandlordData
{
    public function __construct(
        public string $name,
        public string $businessName,
        public string $email,
        public string $phone, // E.164
        public string $password,
    ) {}
}
