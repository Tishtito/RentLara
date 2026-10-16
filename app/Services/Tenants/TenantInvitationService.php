<?php

namespace App\Services\Tenants;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\TenantAuthToken;
use App\Support\CurrentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tenant portal invitations: a one-time link (72 h) emailed to the tenant, who sets a password from it.
 * Sending issues a new token (older links stop working); TenantInvitationIssued → SendTenantInvitation
 * emails it after the transaction commits.
 */
class TenantInvitationService
{
    public function __construct(
        private TenantAuthTokenService $tokens,
        private CurrentAccount $context,
    ) {}

    /** @throws ValidationException no email to send to, or the tenant already activated */
    public function send(Tenant $tenant, ?int $createdBy = null): TenantAuthToken
    {
        if (! $tenant->email) {
            throw ValidationException::withMessages(['email' => 'Add the tenant\'s email first; the invitation is sent by email.']);
        }

        if ($tenant->status !== TenantStatus::Invited) {
            throw ValidationException::withMessages(['tenant' => 'This tenant has already set up their login.']);
        }

        [$token] = $this->tokens->issue($tenant, TenantAuthToken::PURPOSE_INVITATION, createdBy: $createdBy);

        return $token;
    }

    /** The valid invitation for a link, with its tenant, landlord and home; null if invalid, used or expired. */
    public function find(string $raw): ?TenantAuthToken
    {
        $token = $this->tokens->findValid($raw, TenantAuthToken::PURPOSE_INVITATION);

        if (! $token?->tenant) {
            return null;
        }

        $this->context->actingAs($token->account_id, fn () => $token->tenant->load(['account:id,name', 'activeLease.unit.property:id,name']));

        return $token;
    }

    /**
     * Set the password from the link: the tenant becomes active (email verified, since the link was
     * emailed) and the link can't be used again.
     *
     * @throws ValidationException invalid / used / expired link
     */
    public function accept(string $raw, string $password): Tenant
    {
        $token = $this->find($raw);

        if (! $token) {
            throw ValidationException::withMessages(['token' => 'This invitation link has expired or was already used. Ask your landlord to send a new one.']);
        }

        return DB::transaction(function () use ($token, $password) {
            $tenant = $token->tenant;

            $this->context->actingAs($tenant->account_id, fn () => $tenant->forceFill([
                'password' => $password,
                'status' => TenantStatus::Active,
                'email_verified_at' => $tenant->email_verified_at ?? now(),
            ])->save());

            $this->tokens->markUsed($token);

            return $tenant;
        });
    }
}
