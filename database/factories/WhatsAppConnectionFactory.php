<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\WhatsAppConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WhatsAppConnection>
 */
class WhatsAppConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'phone_number_id' => fake()->numerify('1065403522#####'),
            'business_account_id' => fake()->numerify('1022901293#####'),
            'access_token' => 'EAAG'.Str::random(60),
            'app_secret' => Str::random(32),
            'webhook_verify_token' => Str::random(40),
            'display_phone_number' => '+254 700 000001',
            'verified_name' => 'Kims Properties',
            'template_name' => 'rentflow_bill_issued',
            'template_language' => 'en_US',
            'status' => 'active',
            'verified_at' => now(),
        ];
    }
}
