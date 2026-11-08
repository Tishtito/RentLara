<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Models\PersonalAccessToken;
use App\Services\Audit\AuditLogService;
use App\Services\Tenants\TenantService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * Architecture exception (docs/ARCHITECTURE.md): the logged-in tenant model itself must change so this
 * session / token stays logged in, so the controller calls the service directly.
 */
#[Group('Tenant portal', weight: 10)]
class PasswordController extends Controller
{
    /**
     * Change password (tenant portal).
     *
     * The tenant's own password: needs the current one (422 on `current_password` when wrong). This device
     * stays logged in; the tenant's other app logins are signed out.
     */
    public function update(ChangePasswordRequest $request, TenantService $tenants, AuditLogService $audit): JsonResponse
    {
        $tenant = $request->user();
        $token = $tenant->currentAccessToken();

        $tenants->changePassword(
            $tenant,
            (string) $request->input('current_password'),
            (string) $request->input('password'),
            $token instanceof PersonalAccessToken ? $token->id : null,
        );
        $audit->recordAuth('auth.password_changed', $tenant);

        return response()->json(['message' => 'Password changed.']);
    }
}
