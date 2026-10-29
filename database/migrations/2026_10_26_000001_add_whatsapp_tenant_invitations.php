<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant invitations on WhatsApp (docs/WHATSAPP.md):
 * - whatsapp_connections.invitation_template_name: the landlord's approved invitation template
 *   (null = config('whatsapp.invitation_template.name')).
 * - notification_deliveries.payload: encrypted extra data a send needs, e.g. the raw invitation token
 *   for the "Set up my login" button (tenant_auth_tokens only keeps its hash), so a retry can rebuild it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->string('invitation_template_name', 100)->nullable()->after('template_name');
        });

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->text('payload')->nullable()->after('recipient');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_connections', fn (Blueprint $table) => $table->dropColumn('invitation_template_name'));
        Schema::table('notification_deliveries', fn (Blueprint $table) => $table->dropColumn('payload'));
    }
};
