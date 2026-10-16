<?php

namespace App\Events\Commands;

use App\Data\CreateAccountData;
use Illuminate\Foundation\Events\Dispatchable;

/** Intent (platform): create a landlord account and invite its owner. Handled by HandleAccountCreationSubmitted. */
class AccountCreationSubmitted
{
    use Dispatchable;

    public function __construct(
        public readonly CreateAccountData $data,
    ) {}
}
