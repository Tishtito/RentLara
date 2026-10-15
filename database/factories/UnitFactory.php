<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            // Same landlord as the property. Unscoped: factories may run without an account context.
            'account_id' => fn (array $attributes) => Property::withoutGlobalScopes()
                ->whereKey($attributes['property_id'])->value('account_id'),
            'unit_number' => fake()->unique()->numerify('#-##'),
            'type' => '1BR',
            'bedrooms' => 1,
            'default_rent' => '15000.00',
        ];
    }
}
