<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Model events must stay on (no WithoutModelEvents): BelongsToAccount fills
 * account_id and Lease sets next_billing_date in their creating hooks.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->superAdmin()->create([
            'name' => 'Rentflow Admin',
            'email' => 'admin@rentflow.test',
        ]);

        $this->call(KimsPropertiesSeeder::class);
    }
}
