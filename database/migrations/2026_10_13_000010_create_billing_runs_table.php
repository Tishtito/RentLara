<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * billing_runs = one execution of the scheduled billing job for one account.
 * Gives the landlord an audit trail ("29 bills generated, 2 skipped: missing
 * water reading") and lets a failed run be retried safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->date('run_date');
            $table->enum('trigger', ['scheduled', 'manual'])->default('scheduled');
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['running', 'completed', 'completed_with_errors', 'failed'])->default('running');
            $table->unsignedInteger('invoices_created')->default(0);
            $table->unsignedInteger('leases_skipped')->default(0);
            $table->json('errors')->nullable();              // [{lease_id, reason}]
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'run_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_runs');
    }
};
