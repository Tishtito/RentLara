<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenants already living in a unit when the landlord joins Rentflow: their lease keeps its real move-in
 * date, and onboarded_on is the day Rentflow started tracking it (its opening meter readings are taken
 * that day; there is no move-in bill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->date('onboarded_on')->nullable()->after('start_date');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('onboarded_on');
        });
    }
};
