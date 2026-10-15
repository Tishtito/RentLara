<?php

namespace Database\Factories;

use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lease>
 */
class LeaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            // Lease, unit and tenant must all belong to the same landlord.
            'account_id' => fn (array $attributes) => Unit::withoutGlobalScopes()
                ->whereKey($attributes['unit_id'])->value('account_id'),
            'tenant_id' => fn (array $attributes) => Tenant::factory()
                ->state(['account_id' => $attributes['account_id']]),
            'start_date' => now()->subMonth()->toDateString(),
            'monthly_rent' => '15000.00',
        ];
    }
}
