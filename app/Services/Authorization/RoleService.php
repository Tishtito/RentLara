<?php

namespace App\Services\Authorization;

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Support\CurrentAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles inside a landlord account: the shared templates (account_id NULL, edited only by super admins,
 * Owner never) and the account's own custom roles (created by anyone with "roles.manage").
 */
class RoleService
{
    public function __construct(private CurrentAccount $context) {}

    /** Templates first, then this account's roles, each with permissions and how many staff have it here. */
    public function forAccount(int $accountId): Collection
    {
        $roles = Role::query()
            ->where(fn ($q) => $q->whereNull($this->teamKey())->orWhere($this->teamKey(), $accountId))
            ->with('permissions:id,name')
            ->get();

        $counts = DB::table(config('permission.table_names.model_has_roles'))
            ->where($this->teamKey(), $accountId)
            ->where('model_type', 'user')
            ->groupBy('role_id')
            ->selectRaw('role_id, COUNT(*) as total')
            ->pluck('total', 'role_id');

        foreach ($roles as $role) {
            $role->setAttribute('users_count', (int) ($counts[$role->id] ?? 0));
        }

        return $this->sorted($roles);
    }

    /** The shared templates (platform). */
    public function templates(): Collection
    {
        return $this->sorted(Role::query()->whereNull($this->teamKey())->with('permissions:id,name')->get());
    }

    /** A role this account may use: a template or one of its own. Null otherwise (other account → 404). */
    public function findForAccount(int $roleId, int $accountId): ?Role
    {
        return Role::query()->whereKey($roleId)
            ->where(fn ($q) => $q->whereNull($this->teamKey())->orWhere($this->teamKey(), $accountId))
            ->first();
    }

    /**
     * Create (no $roleId) or edit one of the account's own roles.
     *
     * @param  list<string>  $permissions
     *
     * @throws ValidationException duplicate / reserved name, or the role is a template
     */
    public function save(int $accountId, string $name, array $permissions, ?int $roleId = null): Role
    {
        $name = trim($name);
        $role = $roleId ? $this->findForAccount($roleId, $accountId) : null;

        if ($roleId && ! $role) {
            abort(404, 'Role not found.');
        }

        if ($role && $this->isTemplate($role)) {
            throw ValidationException::withMessages(['role' => 'Built-in roles can\'t be changed here. Duplicate it to make your own version.']);
        }

        if (SystemRole::tryFrom($name) !== null) {
            throw ValidationException::withMessages(['name' => "\"{$name}\" is a built-in role name. Choose another name."]);
        }

        $taken = Role::query()->where($this->teamKey(), $accountId)->where('name', $name)
            ->when($role, fn ($q) => $q->whereKeyNot($role->id))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'You already have a role with this name.']);
        }

        return DB::transaction(function () use ($role, $accountId, $name, $permissions) {
            $role ??= Role::query()->create(['name' => $name, 'guard_name' => PermissionCatalog::GUARD, $this->teamKey() => $accountId]);
            $role->forceFill(['name' => $name])->save();
            $role->syncPermissions($this->validPermissions($permissions));
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role->load('permissions:id,name');
        });
    }

    /** @throws ValidationException template, or still assigned to someone */
    public function delete(int $accountId, int $roleId): void
    {
        $role = $this->findForAccount($roleId, $accountId) ?? abort(404, 'Role not found.');

        if ($this->isTemplate($role)) {
            throw ValidationException::withMessages(['role' => 'Built-in roles can\'t be deleted.']);
        }

        $assigned = DB::table(config('permission.table_names.model_has_roles'))->where('role_id', $role->id)->exists();
        if ($assigned) {
            throw ValidationException::withMessages(['role' => 'Someone still has this role. Give them another role first.']);
        }

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Super admins: change a template's permissions (every account using it follows). Owner is locked.
     *
     * @param  list<string>  $permissions
     */
    public function updateTemplate(int $roleId, array $permissions): Role
    {
        $role = Role::query()->whereKey($roleId)->whereNull($this->teamKey())->first() ?? abort(404, 'Role not found.');

        if (SystemRole::tryFrom($role->name)?->isLocked()) {
            throw ValidationException::withMessages(['role' => 'The Owner role always has every permission.']);
        }

        $role->syncPermissions($this->validPermissions($permissions));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role->load('permissions:id,name');
    }

    public function isTemplate(Role $role): bool
    {
        return $role->getAttribute($this->teamKey()) === null;
    }

    /** @param  list<string>  $permissions @return list<string> */
    private function validPermissions(array $permissions): array
    {
        $unknown = array_diff($permissions, Permission::values());
        if ($unknown) {
            throw ValidationException::withMessages(['permissions' => 'Unknown permission: '.implode(', ', $unknown).'.']);
        }

        return array_values(array_unique($permissions));
    }

    private function sorted(Collection $roles): Collection
    {
        $order = array_flip(array_column(SystemRole::cases(), 'value'));

        return $roles->sortBy(fn (Role $r) => [$this->isTemplate($r) ? 0 : 1, $order[$r->name] ?? 99, $r->name])->values();
    }

    private function teamKey(): string
    {
        return config('permission.column_names.team_foreign_key');
    }
}
