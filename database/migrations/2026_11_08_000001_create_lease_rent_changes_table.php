<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * History of rent changes on a lease (LeaseService::changeRent). leases.monthly_rent holds the current rent;
 * each change adds a row here. Old bills never change: their items snapshot the rent they charged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_rent_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->decimal('old_rent', 12, 2);
            $table->decimal('new_rent', 12, 2);
            $table->string('reason', 500)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lease_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_rent_changes');
    }
};
