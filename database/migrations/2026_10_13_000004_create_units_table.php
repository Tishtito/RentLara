<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * units = a rentable house within a property (e.g. "House No 7-7").
 * default_rent is the asking rent; the rent actually billed lives on the lease,
 * so it can be negotiated per occupancy without touching the unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('unit_number', 30);              // "7-7"
            $table->string('type', 30)->nullable();         // bedsitter, 1BR, 2BR, shop...
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('floor')->nullable();
            $table->decimal('default_rent', 12, 2)->default(0);
            $table->decimal('default_deposit', 12, 2)->default(0);
            $table->enum('status', ['vacant', 'occupied', 'reserved', 'maintenance'])->default('vacant');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['property_id', 'unit_number']);
            $table->index(['account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
