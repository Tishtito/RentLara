<?php

namespace App\Events;

use App\Models\Account;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A landlord signed up: new account + its first landlord login (hook for a welcome email). */
class LandlordRegistered implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Account $account,
        public readonly User $user,
    ) {}
}
