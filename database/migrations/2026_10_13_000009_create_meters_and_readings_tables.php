<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * meters         = a physical electricity / water meter on a unit.
 * meter_readings = readings captured by the landlord or caretaker each cycle.
 *
 * On a bill: units consumed = this cycle's reading - previous reading,
 * amount = units consumed x charge_rates.rate for the same charge_code.
 * (Paper bill example: electricity 180 - 100 = 80 units x 30 = 2,400.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->string('charge_code', 30);               // matches charge_rates.charge_code
            $table->string('meter_number', 50)->nullable();
            $table->decimal('initial_reading', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);     // replaced meters are deactivated, not deleted
            $table->timestamps();

            $table->index(['unit_id', 'charge_code', 'is_active']);
        });

        Schema::create('meter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('meter_id')->constrained('meters')->cascadeOnDelete();
            $table->date('reading_date');
            $table->decimal('reading', 12, 2);
            $table->string('photo_path')->nullable();        // optional photo of the meter face
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_estimated')->default(false);
            $table->timestamps();

            $table->unique(['meter_id', 'reading_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_readings');
        Schema::dropIfExists('meters');
    }
};
