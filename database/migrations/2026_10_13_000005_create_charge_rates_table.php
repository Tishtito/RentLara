<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * charge_rates = the landlord's billing settings per property:
 *   - electricity  : metered, rate per unit   (e.g. KES 30 / kWh)
 *   - water        : metered, rate per unit   (e.g. KES 170 / m³)
 *   - garbage      : fixed fee per cycle      (e.g. KES 250)
 *   - anything else: fixed (security, service charge...) or metered
 *
 * Rates are versioned with effective_from / effective_to, never edited in place,
 * so a bill generated last month still reproduces with last month's price.
 * unit_id NULL = applies to every unit in the property; set it to override one unit.
 * Rent is NOT here; it is configured per occupancy on leases.monthly_rent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->cascadeOnDelete();
            $table->string('charge_code', 30);               // electricity | water | garbage | security | ...
            $table->string('label');                         // shown on the bill: "Electricity"
            $table->enum('billing_type', ['metered', 'fixed']);
            $table->decimal('rate', 12, 2);                  // per unit (metered) or flat amount (fixed)
            $table->string('unit_of_measure', 20)->nullable(); // kWh, m³ — metered only
            $table->date('effective_from');
            $table->date('effective_to')->nullable();        // NULL = current rate
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['property_id', 'charge_code', 'effective_from'], 'charge_rates_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_rates');
    }
};
