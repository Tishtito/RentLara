<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payments = money received from a tenant against a lease.
 *
 * Balances run per lease (like an M-Pesa statement), not per invoice:
 *   lease balance = leases.opening_balance
 *                 + SUM(invoices.current_charges, not void)
 *                 - SUM(payments.amount, status=confirmed)
 * When the next bill is generated, that balance becomes its balance_brought_forward
 * (the "Arrears" line). invoice_id links a payment to the bill that was open when it
 * came in, which is what drives that invoice's amount_paid and status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained('leases')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->enum('method', ['mpesa', 'bank', 'cash', 'cheque', 'other']);
            $table->string('reference', 60)->nullable();     // M-Pesa code, bank ref, receipt no.
            $table->string('payer_phone', 20)->nullable();
            $table->timestamp('paid_at');
            $table->enum('status', ['pending', 'confirmed', 'reversed'])->default('confirmed');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete(); // NULL = auto (e.g. M-Pesa callback)
            $table->json('meta')->nullable();                 // raw gateway payload
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'method', 'reference']); // blocks duplicate M-Pesa callbacks
            $table->index(['lease_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
