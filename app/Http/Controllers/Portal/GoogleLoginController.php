<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\GoogleLoginRequest;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Services\Auth\TenantAuthService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * "Continue with Google" for the tenant portal: tenants whose email Google has verified and who have
 * already activated their login from the invitation. Same "which home?" step as the password login.
 *
 * Architecture exception (docs/ARCHITECTURE.md): the session / issued token must exist in the
 * response, so these actions call TenantAuthService directly instead of dispatching an intent event.
 */
#[Group('Tenant portal', weight: 10)]
class GoogleLoginController extends Controller
{
    public function __construct(private TenantAuthService $auth) {}

    /**
     * Log in with Google (tenant portal, browser).
     *
     * Send the Google ID token as `credential`. If the person rents from more than one landlord the
     * response is `{"choose_home": true, "homes": [...]}`: post again with the chosen `tenant_id`.
     * Limited to 6 attempts per minute.
     *
     * @unauthenticated
     */
    public function store(GoogleLoginRequest $request): JsonResponse
    {
        $result = $this->auth->loginWithGoogle($request->string('credential'), $this->tenantId($request), $request->boolean('remember'));

        if (! $result['tenant']) {
            return $this->chooseHome($result['homes']);
        }

        $request->session()->regenerate();

        return response()->json(['choose_home' => false, 'tenant' => new TenantResource($result['tenant']->loadMissing('account:id,name'))]);
    }

    /**
     * Issue a tenant API token with Google (tenant app).
     *
     * Like `POST /api/portal/auth/token`, but with a Google ID token instead of a password.
     *
     * @unauthenticated
     */
    public function token(GoogleLoginRequest $request): JsonResponse
    {
        $result = $this->auth->issueTokenForGoogle($request->string('credential'), $request->input('device_name') ?: 'tenant-app', $this->tenantId($request));

        if (! $result['tenant']) {
            return $this->chooseHome($result['homes']);
        }

        return response()->json([
            'choose_home' => false,
            /** @example 1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx */
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'tenant' => new TenantResource($result['tenant']->loadMissing('account:id,name')),
        ]);
    }

    private function tenantId(GoogleLoginRequest $request): ?int
    {
        return $request->filled('tenant_id') ? $request->integer('tenant_id') : null;
    }

    /** @param Collection<int, Tenant> $homes */
    private function chooseHome($homes): JsonResponse
    {
        return response()->json([
            'choose_home' => true,
            'homes' => $homes->map(fn (Tenant $tenant) => [
                'tenant_id' => $tenant->id,
                'account_name' => $tenant->account?->name,
            ])->all(),
        ]);
    }
}
