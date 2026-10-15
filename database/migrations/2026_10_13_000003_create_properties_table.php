<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * properties = a building / plot a landlord owns (e.g. "Kims Apartments, Kikuyu").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20)->nullable();        // short code used in unit labels
            $table->string('address')->nullable();
            $table->string('town')->nullable();
            $table->string('county')->nullable();
            $table->foreignId('caretaker_id')->nullable()  // user with role=caretaker
                ->constrained('users')->nullOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['account_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
