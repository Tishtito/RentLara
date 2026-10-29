<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A property, or a single unit, can bill on its own day of the month (1–28). A lease bills on its unit's
 * day, else its property's, else accounts.billing_day (LeaseService::billingDayForUnit). Null = inherit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedTinyInteger('billing_day')->nullable()->after('caretaker_id');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->unsignedTinyInteger('billing_day')->nullable()->after('default_deposit');
        });
    }

    public function down(): void
    {
        Schema::table('units', fn (Blueprint $table) => $table->dropColumn('billing_day'));
        Schema::table('properties', fn (Blueprint $table) => $table->dropColumn('billing_day'));
    }
};
