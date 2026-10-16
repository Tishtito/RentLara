<?php

namespace App\Http\Controllers\Platform;

use App\Events\Commands\RoleTemplateUpdateSubmitted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateRoleTemplateRequest;
use App\Http\Resources\RoleResource;
use App\Services\Authorization\RoleService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Platform', weight: 20)]
class RoleTemplateController extends Controller
{
    /**
     * List built-in roles.
     *
     * The role templates every account can use (Owner, Manager, Caretaker, Accountant). Super admins only.
     */
    public function index(RoleService $roles): AnonymousResourceCollection
    {
        return RoleResource::collection($roles->templates());
    }

    /**
     * Change a built-in role.
     *
     * Replaces its permissions; every account using it follows immediately. Owner can't be changed.
     */
    public function update(UpdateRoleTemplateRequest $request, int $role): JsonResponse
    {
        RoleTemplateUpdateSubmitted::dispatch($role, $request->input('permissions', []));

        return response()->json(['message' => 'Role saved.'], 202);
    }
}
