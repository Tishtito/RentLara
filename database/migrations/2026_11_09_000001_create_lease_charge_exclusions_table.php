<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant (lease) excused from one of the property's charges (ChargeExclusionService). Applies to bills whose
 * billing day falls in [starts_on, ends_on]; ends_on null = still excused. Follows the lease, not the unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_charge_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->string('charge_code', 30);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lease_id', 'charge_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_charge_exclusions');
    }
};
