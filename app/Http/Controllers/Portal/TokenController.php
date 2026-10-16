<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\IssueTokenRequest;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Services\Auth\TenantAuthService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * API-token login for the tenant portal apps (Android/iOS via Capacitor), where cross-site cookies
 * don't work. The web portal uses the cookie login instead.
 *
 * Architecture exception (docs/ARCHITECTURE.md): the issued token has to be returned in the
 * response, so these actions call TenantAuthService directly instead of dispatching an intent event.
 */
#[Group('Tenant portal', weight: 10)]
class TokenController extends Controller
{
    public function __construct(private TenantAuthService $auth) {}

    /**
     * Issue a tenant API token.
     *
     * For the mobile apps. Log in with email OR national ID + password and send the token as
     * `Authorization: Bearer <token>` on every `/api/portal/*` request. Shown only once.
     *
     * If the person rents from more than one landlord, the response is
     * `{"choose_home": true, "homes": [...]}` and no token is issued yet: ask which home and post
     * again with its `tenant_id`. Limited to 6 attempts per minute.
     *
     * @unauthenticated
     */
    public function store(IssueTokenRequest $request): JsonResponse
    {
        $result = $this->auth->issueToken(
            $request->string('identifier'),
            $request->string('password'),
            $request->input('device_name') ?: 'tenant-app',
            $request->filled('tenant_id') ? $request->integer('tenant_id') : null,
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

        return response()->json([
            'choose_home' => false,
            /** @example 1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx */
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'tenant' => new TenantResource($result['tenant']->loadMissing('account:id,name')),
        ]);
    }

    /**
     * Revoke the tenant API token.
     *
     * Logs out the token this request was made with (the app's "Log out").
     */
    public function destroy(Request $request): Response
    {
        $this->auth->revokeToken($request->user());

        return response()->noContent();
    }
}
