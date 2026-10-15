<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenant_auth_tokens = one-time links for the tenant portal:
 *   - invitation     : landlord creates the tenant, a link goes out by email/SMS/WhatsApp,
 *                      the tenant opens it and sets a password
 *   - password_reset : "forgot password" from the tenant portal
 *
 * Keyed on tenant_id (not email) because tenant emails are only unique per account.
 * Only a SHA-256 hash of the token is stored; the raw token lives in the link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_auth_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('purpose', ['invitation', 'password_reset']);
            $table->string('token_hash', 64)->unique();
            $table->enum('sent_via', ['email', 'sms', 'whatsapp'])->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_auth_tokens');
    }
};
