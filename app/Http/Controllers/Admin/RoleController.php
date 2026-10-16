<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Events\Commands\RoleDeleteRequested;
use App\Events\Commands\RoleSubmitted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveRoleRequest;
use App\Http\Resources\RoleResource;
use App\Services\Authorization\RoleService;
use App\Support\CurrentAccount;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

#[Group('Team & roles', weight: 8)]
class RoleController extends Controller
{
    /**
     * List permissions.
     *
     * Everything a role can allow, grouped for the role editor.
     */
    public function permissions(): JsonResponse
    {
        Gate::authorize(Permission::TeamView->value);

        return response()->json(['data' => collect(Permission::cases())
            ->groupBy(fn (Permission $p) => $p->group())
            ->map(fn ($items, $group) => [
                'group' => $group,
                'permissions' => $items->map(fn (Permission $p) => ['name' => $p->value, 'label' => $p->label()])->values(),
            ])->values()]);
    }

    /**
     * List roles.
     *
     * The built-in roles (shared by every account) and this account's own roles, with their permissions
     * and how many people have each.
     */
    public function index(RoleService $roles, CurrentAccount $context): AnonymousResourceCollection
    {
        Gate::authorize(Permission::TeamView->value);

        return RoleResource::collection($roles->forAccount($this->account($context)));
    }

    /**
     * Create a role.
     *
     * A role of this account, made of any permissions. Built-in names (Owner, Manager…) are reserved.
     */
    public function store(SaveRoleRequest $request, CurrentAccount $context): JsonResponse
    {
        Gate::authorize(Permission::RolesManage->value);

        RoleSubmitted::dispatch($this->account($context), (string) $request->input('name'), $request->input('permissions', []));

        return response()->json(['message' => 'Role created.'], 202);
    }

    /**
     * Edit a role.
     *
     * Only this account's own roles; built-in ones can be duplicated instead. Everyone with the role
     * gets the new permissions immediately.
     */
    public function update(SaveRoleRequest $request, int $role, CurrentAccount $context): JsonResponse
    {
        Gate::authorize(Permission::RolesManage->value);

        RoleSubmitted::dispatch($this->account($context), (string) $request->input('name'), $request->input('permissions', []), $role);

        return response()->json(['message' => 'Role saved.'], 202);
    }

    /**
     * Delete a role.
     *
     * Only this account's own roles, and only when nobody has it.
     */
    public function destroy(int $role, CurrentAccount $context): JsonResponse
    {
        Gate::authorize(Permission::RolesManage->value);

        RoleDeleteRequested::dispatch($this->account($context), $role);

        return response()->json(['message' => 'Role deleted.'], 202);
    }

    private function account(CurrentAccount $context): int
    {
        abort_unless($context->has(), 422, 'Choose an account with the X-Account-Id header.');

        return (int) $context->id();
    }
}
