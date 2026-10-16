<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Support\CurrentAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The logged-in admin: who they are and what they may do in their account. @mixin \App\Models\User */
class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        [$roles, $permissions] = $this->access();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            /** super_admin (platform) or staff (works in a landlord account). */
            'role' => $this->role,
            /** The landlord account; `null` for super admins. */
            'account_id' => $this->account_id,
            /** Role names in the account, e.g. ["Owner"]. */
            'roles' => $roles,
            /** Permission names (App\Enums\Permission); super admins get ["*"]. The UI hides what's not allowed. */
            'permissions' => $permissions,
            'is_owner' => in_array(SystemRole::Owner->value, $roles, true),
        ];
    }

    /**
     * Roles and permissions are per account, so read them in the user's own account (login runs before
     * the `account` middleware has set one).
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function access(): array
    {
        if ($this->resource->isSuperAdmin()) {
            return [[], ['*']];
        }

        if (! $this->account_id) {
            return [[], []];
        }

        return app(CurrentAccount::class)->actingAs($this->account_id, function () {
            $this->resource->unsetRelation('roles')->unsetRelation('permissions');
            $permissions = $this->resource->getAllPermissions()->pluck('name')
                ->filter(fn (string $name) => Permission::tryFrom($name) !== null)
                ->values()->all();

            return [$this->resource->getRoleNames()->values()->all(), $permissions];
        });
    }
}
