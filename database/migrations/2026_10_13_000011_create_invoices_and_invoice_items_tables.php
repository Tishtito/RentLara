<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * invoices      = the monthly bill for one lease (the digital version of the paper bill).
 * invoice_items = its lines: rent, electricity, water, garbage, any other charge.
 *
 * Arrears are NOT a line item: they are the unpaid balance of earlier invoices,
 * snapshotted into balance_brought_forward so the bill reads like the paper one:
 *
 *   Rent 15,000 + Electricity 2,400 + Water 850 + Garbage 250  = current_charges 18,500
 *   Arrears (balance_brought_forward)                            =  1,000
 *   Total due                                                    = 19,500
 *
 * Metered lines copy previous/current reading, units and rate onto the item, so a
 * bill never changes if a rate or reading is edited later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained('leases')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('billing_run_id')->nullable()->constrained('billing_runs')->nullOnDelete();
            $table->string('invoice_number', 40);            // e.g. INV-2026-000123
            $table->date('period_start');
            $table->date('period_end');
            $table->date('issue_date');
            $table->date('due_date');
            $table->decimal('current_charges', 12, 2)->default(0);       // sum of invoice_items
            $table->decimal('balance_brought_forward', 12, 2)->default(0); // arrears (negative = credit)
            $table->decimal('total_due', 12, 2)->default(0);             // current_charges + balance_brought_forward
            $table->decimal('amount_paid', 12, 2)->default(0);           // payments allocated to this invoice
            $table->enum('status', ['draft', 'issued', 'partially_paid', 'paid', 'overdue', 'void'])->default('draft');
            $table->timestamp('sent_at')->nullable();        // first successful delivery on any channel
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['account_id', 'invoice_number']);
            $table->unique(['lease_id', 'period_start']);    // makes the billing job idempotent
            $table->index(['tenant_id', 'status']);
            $table->index(['account_id', 'status', 'due_date']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('charge_code', 30);               // rent | electricity | water | garbage | ...
            $table->string('description');                   // "Electricity"
            $table->foreignId('charge_rate_id')->nullable()->constrained('charge_rates')->nullOnDelete();
            $table->foreignId('meter_id')->nullable()->constrained('meters')->nullOnDelete();
            $table->foreignId('previous_reading_id')->nullable()->constrained('meter_readings')->nullOnDelete();
            $table->foreignId('current_reading_id')->nullable()->constrained('meter_readings')->nullOnDelete();
            $table->decimal('previous_reading', 12, 2)->nullable(); // snapshot: 100
            $table->decimal('current_reading', 12, 2)->nullable();  // snapshot: 180
            $table->decimal('quantity', 12, 2)->default(1);         // units consumed (80) or 1 for fixed
            $table->decimal('rate', 12, 2);                         // snapshot: 30
            $table->decimal('amount', 12, 2);                       // quantity x rate = 2,400
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
