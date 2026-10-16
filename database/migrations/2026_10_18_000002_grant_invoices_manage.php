<?php

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Services\Authorization\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * New permission "invoices.manage" (resend and void bills). Built-in templates only get their defaults
 * when first created, so give it to the existing Manager and Accountant templates here (Owner gets every
 * permission on sync).
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalog = app(PermissionCatalog::class);
        $catalog->sync();

        foreach ([SystemRole::Manager, SystemRole::Accountant] as $template) {
            $catalog->template($template)->givePermissionTo(Permission::InvoicesManage->value);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Leave the permission in place; removing it could lock people out of bills they manage.
    }
};
