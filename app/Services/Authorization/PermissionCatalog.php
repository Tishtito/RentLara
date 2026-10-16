<?php

namespace App\Services\Authorization;

use App\Enums\Permission;
use App\Enums\SystemRole;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Writes the permission catalogue (App\Enums\Permission) and the built-in role templates to the
 * database. Idempotent: run on deploy (`php artisan permissions:sync`), by migrations and seeders.
 * Templates get their default permissions only when first created, so super admins' later edits
 * survive; Owner is always given every permission.
 */
class PermissionCatalog
{
    public const GUARD = 'web';

    public function sync(): void
    {
        foreach (Permission::cases() as $permission) {
            PermissionModel::query()->firstOrCreate(['name' => $permission->value, 'guard_name' => self::GUARD]);
        }

        foreach (SystemRole::cases() as $template) {
            $role = $this->template($template);

            if ($role->wasRecentlyCreated || $template->isLocked()) {
                $role->syncPermissions(array_map(fn (Permission $p) => $p->value, $template->defaultPermissions()));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** The shared template role (account_id NULL), created if missing. */
    public function template(SystemRole $template): Role
    {
        return Role::query()->firstOrCreate(
            ['name' => $template->value, 'guard_name' => self::GUARD, config('permission.column_names.team_foreign_key') => null],
        );
    }
}
