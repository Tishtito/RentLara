<?php

namespace App\Services\Tenants;

use App\Events\TenantInvitationIssued;
use App\Events\TenantPasswordResetIssued;
use App\Models\Tenant;
use App\Models\TenantAuthToken;
use App\Support\CurrentAccount;
use Illuminate\Support\Str;

/**
 * One-time invitation / password-reset links for the tenant portal.
 * Only the SHA-256 hash is stored; the raw token goes out in the link.
 */
class TenantAuthTokenService
{
    public function __construct(private CurrentAccount $context) {}

    /**
     * Create a token and return [model, rawToken]. Any earlier unused token of the
     * same purpose is expired, so only the newest link works.
     *
     * @return array{0: TenantAuthToken, 1: string}
     */
    public function issue(Tenant $tenant, string $purpose, int $validForHours = 72, ?int $createdBy = null): array
    {
        TenantAuthToken::query()
            ->where('tenant_id', $tenant->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->update(['expires_at' => now()]);

        $raw = Str::random(64);

        $token = TenantAuthToken::create([
            'account_id' => $tenant->account_id,
            'tenant_id' => $tenant->id,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $raw),
            'created_by' => $createdBy,
            'expires_at' => now()->addHours($validForHours),
        ]);

        match ($purpose) {
            TenantAuthToken::PURPOSE_INVITATION => TenantInvitationIssued::dispatch($token, $raw),
            TenantAuthToken::PURPOSE_PASSWORD_RESET => TenantPasswordResetIssued::dispatch($token, $raw),
            default => null,
        };

        return [$token, $raw];
    }

    /** Stop every unused link of this purpose working (e.g. after the password was changed another way). */
    public function expireAll(Tenant $tenant, string $purpose): void
    {
        TenantAuthToken::query()
            ->where('tenant_id', $tenant->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);
    }

    /** Look up a raw token from a link. Runs before any login, so across all accounts. */
    public function findValid(string $raw, string $purpose): ?TenantAuthToken
    {
        return $this->context->withoutScope(fn () => TenantAuthToken::query()
            ->with('tenant')
            ->where('token_hash', hash('sha256', $raw))
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first());
    }

    public function markUsed(TenantAuthToken $token): void
    {
        $this->context->withoutScope(fn () => $token->forceFill(['used_at' => now()])->save());
    }
}
