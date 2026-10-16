<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * whatsapp_connections = a landlord's own WhatsApp Business number (Meta WhatsApp Cloud API).
 * Each landlord has their own Meta app, so credentials, app secret (webhook signatures) and
 * webhook verify token are per account. Tokens are stored encrypted (APP_KEY).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained('accounts')->cascadeOnDelete();
            $table->string('phone_number_id', 64);           // Meta "Phone number ID"
            $table->string('business_account_id', 64);       // WhatsApp Business Account (WABA) ID
            $table->text('access_token');                    // encrypted: system-user token
            $table->text('app_secret');                      // encrypted: verifies webhook signatures
            $table->string('webhook_verify_token', 64);      // landlord pastes this into Meta's webhook setup
            $table->string('display_phone_number', 32)->nullable(); // "+254 700 000001", from Meta
            $table->string('verified_name')->nullable();     // business name Meta shows to tenants
            $table->string('template_name', 100)->default('rentflow_bill_issued');
            $table->string('template_language', 20)->default('en');
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->timestamp('verified_at')->nullable();    // last successful credential check
            $table->timestamps();

            $table->index('phone_number_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_connections');
    }
};
