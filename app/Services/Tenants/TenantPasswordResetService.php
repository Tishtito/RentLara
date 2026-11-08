<?php

namespace App\Services\Tenants;

use App\Enums\TenantStatus;
use App\Events\TenantPasswordChanged;
use App\Models\Tenant;
use App\Models\TenantAuthToken;
use App\Support\CurrentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tenant portal "forgot password": one-time links (tenant_auth_tokens, purpose password_reset) emailed to the
 * tenant, who chooses a new password from {PORTAL_URL}/reset-password/{token}. TenantAuthTokenService::issue()
 * dispatches TenantPasswordResetIssued → SendTenantPasswordReset emails it.
 *
 * Tenants are per landlord (decision #1): one email / national ID can match several tenant rows, and each
 * gets its own link, sent in that landlord's name. Only tenants who have a login (active, password set) and
 * an email; invited tenants use their invitation instead.
 */
class TenantPasswordResetService
{
    /** "Forgot password" from the login page. */
    public const REQUEST_VALID_HOURS = 1;

    /** Sent by the landlord from the tenant's page. */
    public const LANDLORD_VALID_HOURS = 24;

    public function __construct(
        private TenantService $tenants,
        private TenantAuthTokenService $tokens,
        private CurrentAccount $context,
    ) {}

    /** Forgot password: a link to every matching login with an email. Looks the same to the caller either way. */
    public function request(string $identifier): void
    {
        if (trim($identifier) === '') {
            return;
        }

        $this->tenants->findForPortalLogin($identifier)
            ->filter(fn (Tenant $tenant) => $tenant->email && $tenant->account?->isActive())
            ->each(fn (Tenant $tenant) => $this->context->actingAs(
                $tenant->account_id,
                fn () => $this->tokens->issue($tenant, TenantAuthToken::PURPOSE_PASSWORD_RESET, self::REQUEST_VALID_HOURS),
            ));
    }

    /**
     * The landlord emails the tenant a reset link (older links stop working).
     *
     * @throws ValidationException no email, or the tenant hasn't set up their login yet
     */
    public function sendLink(Tenant $tenant, ?int $createdBy = null): TenantAuthToken
    {
        if (! $tenant->email) {
            throw ValidationException::withMessages(['email' => 'Add the tenant\'s email first; the reset link is sent by email.']);
        }

        if ($tenant->status !== TenantStatus::Active || ! $tenant->hasActivatedPortal()) {
            throw ValidationException::withMessages(['tenant' => 'This tenant hasn\'t set up their login yet. Resend the invitation instead.']);
        }

        [$token] = $this->tokens->issue($tenant, TenantAuthToken::PURPOSE_PASSWORD_RESET, self::LANDLORD_VALID_HOURS, $createdBy);

        return $token;
    }

    /** The valid link with its tenant, landlord and home; null if invalid, used, expired, or the login is gone. */
    public function find(string $raw): ?TenantAuthToken
    {
        $token = $this->tokens->findValid($raw, TenantAuthToken::PURPOSE_PASSWORD_RESET);
        $tenant = $token?->tenant;

        if (! $tenant || $tenant->status !== TenantStatus::Active) {
            return null;
        }

        $this->context->actingAs($token->account_id, fn () => $tenant->load(['account:id,name,status', 'activeLease.unit.property:id,name']));

        return $tenant->account?->isActive() ? $token : null;
    }

    /**
     * Choose a new password from the link. The link can't be used again and the tenant's app tokens are
     * revoked (the caller logs this device in).
     *
     * @throws ValidationException invalid / used / expired link
     */
    public function reset(string $raw, string $password): Tenant
    {
        $token = $this->find($raw);

        if (! $token) {
            throw ValidationException::withMessages(['token' => 'This link has expired or was already used. Ask for a new one from the login page.']);
        }

        return DB::transaction(function () use ($token, $password) {
            $tenant = $token->tenant;

            $this->context->actingAs($tenant->account_id, function () use ($tenant, $password) {
                $tenant->forceFill(['password' => $password])->save();
                $this->tenants->revokeAppTokens($tenant);
            });

            $this->tokens->markUsed($token);
            TenantPasswordChanged::dispatch($tenant, 'reset');

            return $tenant;
        });
    }
}
