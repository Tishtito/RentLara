<?php

namespace App\Events\Commands;

use App\Data\AccountProfileData;
use Illuminate\Foundation\Events\Dispatchable;

/** Intent (platform): change a landlord account's details and billing settings. Handled by HandleAccountProfileSubmitted. */
class AccountProfileSubmitted
{
    use Dispatchable;

    public function __construct(
        public readonly AccountProfileData $data,
    ) {}
}
