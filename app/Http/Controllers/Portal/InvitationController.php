<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\AcceptInvitationRequest;
use App\Http\Resources\TenantResource;
use App\Services\Auth\TenantAuthService;
use App\Services\Tenants\TenantInvitationService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * The link in the invitation email (/invite/{token} in the tenant portal).
 *
 * Architecture exception (docs/ARCHITECTURE.md): accepting logs the tenant in, so the session / token
 * must exist in the response; the controller calls the services directly.
 */
#[Group('Tenant portal', weight: 10)]
class InvitationController extends Controller
{
    public function __construct(private TenantInvitationService $invitations) {}

    /**
     * Open an invitation.
     *
     * Who it's for and how they'll log in. 404 when the link is invalid, already used or expired.
     *
     * @unauthenticated
     */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->invitations->find($token) ?? abort(404, 'This invitation link has expired or was already used.');
        $tenant = $invitation->tenant;
        $unit = $tenant->activeLease?->unit;

        return response()->json([
            'first_name' => $tenant->first_name,
            'landlord' => $tenant->account?->name,
            /** @example House 7-7, Kims Apartments */
            'home' => $unit ? "House {$unit->unit_number}, {$unit->property?->name}" : null,
            /** What they can type as their login. */
            'log_in_with' => array_values(array_filter([$tenant->email, $tenant->national_id ? "ID {$tenant->national_id}" : null])),
            'expires_at' => $invitation->expires_at,
        ]);
    }

    /**
     * Accept an invitation.
     *
     * Sets the tenant's password and logs them in: a cookie session in the browser, or an API token
     * when `device_name` is sent (apps). The link can't be used again. Limited to 6 attempts per minute.
     *
     * @unauthenticated
     */
    public function store(AcceptInvitationRequest $request, string $token, TenantAuthService $auth): JsonResponse
    {
        $tenant = $this->invitations->accept($token, $request->string('password'));
        $resource = new TenantResource($tenant->loadMissing('account:id,name'));

        if ($request->filled('device_name')) {
            return response()->json([
                'token' => $auth->issueTokenFor($tenant, (string) $request->input('device_name')),
                'token_type' => 'Bearer',
                'tenant' => $resource,
            ]);
        }

        $auth->loginTenant($tenant);
        $request->session()->regenerate();

        return response()->json(['tenant' => $resource]);
    }
}
