<?php

namespace Tests\Concerns;

use App\Models\User;
use Laravel\Sanctum\Sanctum;

trait ActsAsAdmin
{
    /** Log in to the admin API as a seeded user (landlord@ / caretaker@rentflow.test). */
    protected function actingAsAdmin(string $email = 'landlord@rentflow.test'): User
    {
        $user = User::where('email', $email)->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }
}
