<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accounts = one landlord business (the "tenant" in multi-tenancy terms).
 * Every landlord-owned row in the system carries account_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');                       // e.g. "Kims Properties"
            $table->string('slug')->unique();             // used in URLs / subdomains
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('currency', 3)->default('KES');
            $table->string('timezone')->default('Africa/Nairobi');
            $table->string('invoice_prefix', 10)->default('INV');
            $table->unsignedSmallInteger('billing_cycle_days')->default(29); // monthly cycle length
            $table->unsignedSmallInteger('payment_due_days')->default(5);   // due date = issue date + N
            $table->enum('status', ['active', 'suspended', 'closed'])->default('active');
            $table->json('settings')->nullable();         // SMS / WhatsApp sender IDs, branding, etc.
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
