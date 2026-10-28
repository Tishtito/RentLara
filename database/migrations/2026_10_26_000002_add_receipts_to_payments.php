<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment receipts (PaymentReceiptNotificationService, ReceiptPdf):
 * - payments.receipt_number: RCT-{year}-{000001}, own sequence per landlord and year, set when a
 *   payment is confirmed. Existing confirmed payments are numbered here, oldest first.
 * - payments.balance_after: the lease's running balance just after this payment (snapshot for the
 *   receipt; null on payments recorded before receipts existed).
 * - whatsapp_connections.receipt_template_name: the landlord's receipt template (null = config default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('receipt_number', 30)->nullable()->after('reference');
            $table->decimal('balance_after', 12, 2)->nullable()->after('amount');
            $table->unique(['account_id', 'receipt_number']);
        });

        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->string('receipt_template_name', 100)->nullable()->after('invitation_template_name');
        });

        $sequence = [];
        DB::table('payments')
            ->where('status', 'confirmed')
            ->orderBy('paid_at')->orderBy('id')
            ->select(['id', 'account_id', 'paid_at'])
            ->each(function ($payment) use (&$sequence) {
                $year = Carbon::parse($payment->paid_at)->year;
                $next = $sequence[$payment->account_id][$year] = ($sequence[$payment->account_id][$year] ?? 0) + 1;

                DB::table('payments')->where('id', $payment->id)->update([
                    'receipt_number' => "RCT-{$year}-".str_pad((string) $next, 6, '0', STR_PAD_LEFT),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('whatsapp_connections', fn (Blueprint $table) => $table->dropColumn('receipt_template_name'));

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['account_id', 'receipt_number']);
            $table->dropColumn(['receipt_number', 'balance_after']);
        });
    }
};
