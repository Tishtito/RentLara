<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who did what: one row per accepted write (intent event) and per login/logout, across every account.
 * Rows are never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('actor_type', 10);                 // user | tenant | system | guest
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();          // snapshot: still readable if the login is removed
            $table->boolean('via_platform')->default(false);   // a super admin did it (on the platform or inside the account)
            $table->string('action', 60);                      // payment.submitted, auth.login…
            $table->string('summary');
            $table->string('subject_type', 30)->nullable();    // lease, property, user…
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('data')->nullable();                  // the request, secrets redacted
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['account_id', 'created_at']);
            $table->index('action');
            $table->index(['actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
