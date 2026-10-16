<?php

namespace App\Events\Commands;

use App\Data\RegisterLandlordData;
use Illuminate\Foundation\Events\Dispatchable;

/** Intent: someone signed up as a new landlord. Handled by HandleLandlordRegistrationSubmitted. */
class LandlordRegistrationSubmitted
{
    use Dispatchable;

    public function __construct(public readonly RegisterLandlordData $data) {}
}
