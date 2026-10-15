<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users = admin-portal logins only (super admin, landlords, caretakers).
 * Tenants log into the tenant portal through the separate `tenants` table / guard.
 *
 * Extends Laravel's default users table (0001_01_01_000000_create_users_table)
 * instead of replacing it, so password_reset_tokens and sessions stay intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // NULL for super admins, who sit above all accounts.
            $table->foreignId('account_id')->nullable()->after('id')
                ->constrained('accounts')->cascadeOnDelete();
            $table->enum('role', ['super_admin', 'landlord', 'caretaker'])
                ->default('landlord')->after('account_id');
            $table->string('phone', 20)->nullable()->after('email');
            $table->enum('status', ['active', 'suspended'])->default('active')->after('password');
            $table->timestamp('last_login_at')->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
            $table->dropColumn(['role', 'phone', 'status', 'last_login_at', 'deleted_at']);
        });
    }
};
