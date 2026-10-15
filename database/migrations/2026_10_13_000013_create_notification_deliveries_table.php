<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * notification_deliveries = one row per message per channel, so the landlord can
 * see "bill sent by SMS ✓, WhatsApp ✗ (number not on WhatsApp)" and failed sends
 * can be retried without re-sending the ones that worked.
 *
 * notifiable_* is polymorphic: an invoice (monthly bill), a payment (receipt),
 * a tenant_auth_token (invitation) or anything added later (reminders).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->nullableMorphs('notifiable');            // notifiable_type, notifiable_id
            $table->string('type', 50);                       // bill_issued | payment_receipt | invitation | reminder
            $table->enum('channel', ['email', 'sms', 'whatsapp']);
            $table->string('recipient');                      // email address or phone number
            $table->enum('status', ['queued', 'sent', 'delivered', 'failed'])->default('queued');
            $table->string('provider', 30)->nullable();       // africastalking, twilio, meta, smtp...
            $table->string('provider_message_id')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index('provider_message_id');            // delivery-report webhooks look up by this
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
