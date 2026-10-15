<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenants = people renting units. They authenticate on the tenant portal
 * (separate "tenant" auth guard) with email OR national ID + password.
 *
 * Scoped per account: each landlord owns their tenant records, and one landlord
 * can never see whether a person also rents elsewhere. If the same person rents
 * from two landlords they have two rows; the login flow asks which account
 * when an email / national ID matches more than one.
 *
 * password is NULL until the tenant accepts their invitation and sets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('national_id', 20)->nullable();
            $table->string('phone', 20);                     // E.164, e.g. +2547XXXXXXXX (SMS)
            $table->string('whatsapp_phone', 20)->nullable(); // NULL = same as phone
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamp('email_verified_at')->nullable();
            $table->json('notification_channels')->nullable(); // ["email","sms","whatsapp"]; NULL = account default
            $table->string('next_of_kin_name')->nullable();
            $table->string('next_of_kin_phone', 20)->nullable();
            $table->enum('status', ['invited', 'active', 'inactive'])->default('invited');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['account_id', 'email']);
            $table->unique(['account_id', 'national_id']);
            $table->index('email');        // portal login lookup across accounts
            $table->index('national_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
