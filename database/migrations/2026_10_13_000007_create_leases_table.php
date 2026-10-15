<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * leases = a tenant occupying a unit for a period. This is the billing anchor:
 *   - monthly_rent is the rent configured for THIS occupied house
 *   - next_billing_date drives the cycle job: when it is <= today, the job bills
 *     the lease and pushes next_billing_date forward by accounts.billing_cycle_days (29)
 *
 * Moving out ends the lease (status=ended, end_date set); history stays intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('monthly_rent', 12, 2);
            $table->decimal('deposit_amount', 12, 2)->default(0);
            $table->decimal('opening_balance', 12, 2)->default(0); // arrears carried in from before the system
            $table->date('last_billed_on')->nullable();
            $table->date('next_billing_date');                       // start_date + cycle on creation
            $table->enum('status', ['active', 'notice', 'ended'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'next_billing_date']); // the billing job's main query
            $table->index(['unit_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leases');
    }
};
