<?php

use App\Services\Billing\BillingCalendar;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing restructure (16 Oct 2026):
 *  - accounts.billing_day (1–28, default 5): every lease bills on that day of the month, replacing the
 *    rolling cycle (billing_cycle_days).
 *  - invoices.type: move_in (deposit + prorated rent, made with the lease), monthly (billing day),
 *    adjustment (by hand).
 *  - leases.deposit_invoiced_at: the deposit is never charged twice.
 * Existing leases move to the first billing day on or after their current next bill date (the few days
 * in between are not billed: a one-off switch-over).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedTinyInteger('billing_day')->default(BillingCalendar::DEFAULT_BILLING_DAY)->after('invoice_prefix');
        });
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('billing_cycle_days');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('type', 20)->default('monthly')->after('invoice_number');
            $table->index(['account_id', 'type']);
        });

        Schema::table('leases', function (Blueprint $table) {
            $table->timestamp('deposit_invoiced_at')->nullable()->after('deposit_amount');
        });

        DB::table('leases')->whereIn('status', ['active', 'notice'])->whereNotNull('next_billing_date')->orderBy('id')
            ->each(function ($lease) {
                $next = new DateTimeImmutable(substr((string) $lease->next_billing_date, 0, 10));
                $day = (int) (DB::table('accounts')->where('id', $lease->account_id)->value('billing_day') ?: BillingCalendar::DEFAULT_BILLING_DAY);
                DB::table('leases')->where('id', $lease->id)->update([
                    'next_billing_date' => BillingCalendar::nextBillingDateAfter($next->modify('-1 day'), $day)->format('Y-m-d'),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('deposit_invoiced_at');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'type']);
            $table->dropColumn('type');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedSmallInteger('billing_cycle_days')->default(30)->after('invoice_prefix');
        });
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('billing_day');
        });
    }
};
