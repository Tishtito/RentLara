<?php

namespace Database\Factories;

use App\Enums\TenantStatus;
use App\Models\Account;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'national_id' => fake()->unique()->numerify('########'),
            'phone' => '+2547'.fake()->numerify('########'),
            'password' => 'password',
            'status' => TenantStatus::Active,
        ];
    }

    /** Created by the landlord, invitation not yet accepted. */
    public function invited(): static
    {
        return $this->state(fn (array $attributes) => [
            'password' => null,
            'status' => TenantStatus::Invited,
        ]);
    }
}
