<?php

namespace App\Events\Commands;

use App\Data\StaffData;
use Illuminate\Foundation\Events\Dispatchable;

/** Intent: invite a team member (no userId) or change one. Handled by HandleStaffSubmitted. */
class StaffSubmitted
{
    use Dispatchable;

    public function __construct(public readonly StaffData $data) {}
}
