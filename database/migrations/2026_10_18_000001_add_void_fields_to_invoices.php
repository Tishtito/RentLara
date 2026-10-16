<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Voiding bills. A voided bill keeps its row (bills are never deleted) but frees its period, so the lease
 * can be billed for it again: the old unique (lease_id, period_start) becomes a unique billing_key that
 * only live bills carry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('billing_key', 40)->nullable()->after('billing_run_id'); // "{lease_id}:{period_start}", null when void
            $table->timestamp('voided_at')->nullable()->after('sent_at');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable()->after('voided_by');
        });

        DB::table('invoices')->where('status', '!=', 'void')->orderBy('id')->each(function ($row) {
            DB::table('invoices')->where('id', $row->id)->update(['billing_key' => $row->lease_id.':'.substr((string) $row->period_start, 0, 10)]);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->index('lease_id'); // the dropped unique index was also the lease_id foreign key's index (MySQL)
            $table->dropUnique(['lease_id', 'period_start']);
            $table->unique('billing_key');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['billing_key']);
            $table->unique(['lease_id', 'period_start']);
            $table->dropIndex(['lease_id']);
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['billing_key', 'voided_at', 'void_reason']);
        });
    }
};
