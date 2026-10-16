<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\LoginRequest;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Services\Auth\TenantAuthService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Cookie-session login for the React tenant portal (Sanctum SPA auth, "tenant" guard).
 *
 * Architecture exception (docs/ARCHITECTURE.md): the session must be started before the response,
 * so these actions call TenantAuthService directly instead of dispatching an intent event.
 */
#[Group('Tenant portal', weight: 10)]
class SessionController extends Controller
{
    public function __construct(private TenantAuthService $auth) {}

    /**
     * Log in (tenant portal).
     *
     * Log in with email OR national ID + password. Call `GET /sanctum/csrf-cookie` first.
     *
     * If the person rents from more than one landlord, the response is
     * `{"choose_home": true, "homes": [...]}` and nobody is logged in yet: ask which home and post
     * again with its `tenant_id`. Otherwise the response is `{"choose_home": false, "tenant": {...}}`.
     * Limited to 6 attempts per minute.
     *
     * @unauthenticated
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            $request->string('identifier'),
            $request->string('password'),
            $request->filled('tenant_id') ? $request->integer('tenant_id') : null,
            $request->boolean('remember'),
        );

        if (! $result['tenant']) {
            return response()->json([
                'choose_home' => true,
                'homes' => $result['homes']->map(fn (Tenant $tenant) => [
                    'tenant_id' => $tenant->id,
                    'account_name' => $tenant->account?->name,
                ])->all(),
            ]);
        }

        $request->session()->regenerate();

        return response()->json([
            'choose_home' => false,
            'tenant' => new TenantResource($result['tenant']->loadMissing('account:id,name')),
        ]);
    }

    /**
     * Current tenant.
     *
     * Works with the cookie session (web portal) and with an API token (apps).
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['tenant' => new TenantResource($request->user()->loadMissing('account:id,name'))]);
    }

    /**
     * Log out (tenant portal).
     *
     * Ends the tenant cookie session. An admin-portal login in the same browser is not affected.
     */
    public function destroy(Request $request): Response
    {
        $this->auth->logout();

        $request->session()->regenerate(true);
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
