<?php

namespace App\Http\Controllers\Portal;

use App\Events\Commands\TenantPasswordResetRequested;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\AcceptInvitationRequest;
use App\Http\Requests\Portal\ForgotPasswordRequest;
use App\Http\Resources\TenantResource;
use App\Services\Auth\TenantAuthService;
use App\Services\Tenants\TenantPasswordResetService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * Tenant "forgot password": a one-time link emailed to the tenant (/reset-password/{token} in the tenant portal).
 *
 * Architecture exception (docs/ARCHITECTURE.md) for `store`: it logs the tenant in, so the session / token
 * must exist in the response; it calls the services directly.
 */
#[Group('Tenant portal', weight: 10)]
class PasswordResetController extends Controller
{
    public function __construct(private TenantPasswordResetService $resets) {}

    /**
     * Forgot password (tenant portal).
     *
     * Emails a "choose a new password" link (valid 1 hour) to every tenant login matching this email or
     * national ID: one email per landlord. Only logins with an email on file; the answer is the same
     * whether or not anything matched. Limited to 3 attempts per minute.
     *
     * @unauthenticated
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        TenantPasswordResetRequested::dispatch((string) $request->input('identifier'));

        return response()->json(['message' => 'If this login exists, we\'ve emailed a link to the email address on file.'], 202);
    }

    /**
     * Open a password reset link.
     *
     * Who it's for. 404 when the link is invalid, already used or expired.
     *
     * @unauthenticated
     */
    public function show(string $token): JsonResponse
    {
        $reset = $this->resets->find($token) ?? abort(404, 'This link has expired or was already used.');
        $tenant = $reset->tenant;
        $unit = $tenant->activeLease?->unit;

        return response()->json([
            'first_name' => $tenant->first_name,
            'landlord' => $tenant->account?->name,
            /** @example House 7-7, Kims Apartments */
            'home' => $unit ? "House {$unit->unit_number}, {$unit->property?->name}" : null,
            /** What they can type as their login. */
            'log_in_with' => array_values(array_filter([$tenant->email, $tenant->national_id ? "ID {$tenant->national_id}" : null])),
            'expires_at' => $reset->expires_at,
        ]);
    }

    /**
     * Reset the password.
     *
     * Sets the new password and logs the tenant in: a cookie session in the browser, or an API token when
     * `device_name` is sent (apps). Other app logins are signed out and the link can't be used again.
     * Limited to 6 attempts per minute.
     *
     * @unauthenticated
     */
    public function store(AcceptInvitationRequest $request, string $token, TenantAuthService $auth): JsonResponse
    {
        $tenant = $this->resets->reset($token, $request->string('password'));
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
