<?php

use App\Enums\SystemRole;
use App\Services\Authorization\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles move from users.role (super_admin | landlord | caretaker) to Spatie roles per account:
 * landlords become the account's Owner, caretakers get the Caretaker template. users.role keeps only
 * the platform distinction: super_admin or staff. users.status gains "invited" (staff who haven't set a
 * password yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalog = app(PermissionCatalog::class);
        $catalog->sync();

        $roleIds = [
            'landlord' => $catalog->template(SystemRole::Owner)->id,
            'caretaker' => $catalog->template(SystemRole::Caretaker)->id,
        ];

        DB::table('users')->whereIn('role', array_keys($roleIds))->whereNotNull('account_id')->orderBy('id')
            ->each(function ($user) use ($roleIds) {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $roleIds[$user->role],
                    'model_type' => 'user',
                    'model_id' => $user->id,
                    'account_id' => $user->account_id,
                ]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('staff')->change();
            // invited = added by a landlord, hasn't set a password yet (team invitations).
            $table->string('status', 20)->default('active')->change();
        });

        DB::table('users')->whereIn('role', ['landlord', 'caretaker'])->update(['role' => 'staff']);
    }

    public function down(): void
    {
        $owners = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', SystemRole::Owner->value)->where('model_type', 'user')
            ->pluck('model_id');

        DB::table('users')->where('role', 'staff')->whereIn('id', $owners)->update(['role' => 'landlord']);
        DB::table('users')->where('role', 'staff')->update(['role' => 'caretaker']);
    }
};
