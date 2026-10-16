<?php

namespace App\Services\Auth;

use App\Models\Tenant;
use App\Services\Tenants\TenantService;
use App\Support\CurrentAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Tenant-portal logins (tenants table): cookie sessions ("tenant" guard) for the web portal and
 * Sanctum API tokens (ability `portal`) for the Android/iOS apps.
 *
 * Tenants are scoped per landlord (confirmed decision #1), so one email or national ID can match
 * several tenant rows. Only rows whose password matches count; if more than one is left the
 * person must pick which landlord ("home") to log into.
 */
class TenantAuthService
{
    public function __construct(
        private TenantService $tenants,
        private CurrentAccount $context,
        private GoogleIdTokenVerifier $google,
    ) {}

    /**
     * Find the tenant for these credentials, or the homes to choose from.
     *
     * @return array{tenant: Tenant|null, homes: Collection<int, Tenant>} `tenant` is set when one home
     *                                                                    matched (or was chosen); otherwise `homes` lists the choices
     *
     * @throws ValidationException no match, or a tenant_id that is not one of the matches
     */
    public function resolve(string $identifier, string $password, ?int $tenantId = null): array
    {
        $matches = $this->tenants->findForPortalLogin($identifier)
            ->filter(fn (Tenant $tenant) => $tenant->account?->isActive() && Hash::check($password, $tenant->password))
            ->values();

        if ($matches->isEmpty()) {
            throw ValidationException::withMessages(['identifier' => 'These credentials do not match our records.']);
        }

        return $this->pick($matches, $tenantId);
    }

    /**
     * "Continue with Google": the tenant records (one per landlord) whose email Google verified.
     * Only tenants who have activated their portal login; Google never creates one.
     *
     * @return array{tenant: Tenant|null, homes: Collection<int, Tenant>}
     *
     * @throws ValidationException invalid Google token, no tenant with that email, or a wrong tenant_id
     */
    public function resolveGoogle(string $idToken, ?int $tenantId = null): array
    {
        $google = $this->google->verify($idToken);

        $matches = $this->tenants->findForPortalLogin($google['email'])
            ->filter(fn (Tenant $tenant) => $tenant->account?->isActive())
            ->values();

        if ($matches->isEmpty()) {
            throw ValidationException::withMessages(['credential' => 'No tenant login uses this Google email. Use the link in your invitation, or log in with your email or national ID.']);
        }

        return $this->pick($matches, $tenantId);
    }

    /**
     * Cookie-session login (web tenant portal). The caller regenerates the session id.
     *
     * @return array{tenant: Tenant|null, homes: Collection<int, Tenant>}
     */
    public function login(string $identifier, string $password, ?int $tenantId = null, bool $remember = false): array
    {
        return $this->startSession($this->resolve($identifier, $password, $tenantId), $remember);
    }

    /** @return array{tenant: Tenant|null, homes: Collection<int, Tenant>} */
    public function loginWithGoogle(string $idToken, ?int $tenantId = null, bool $remember = false): array
    {
        return $this->startSession($this->resolveGoogle($idToken, $tenantId), $remember);
    }

    /**
     * API-token login (tenant portal apps). The plain-text token is returned once.
     *
     * @return array{tenant: Tenant|null, homes: Collection<int, Tenant>, token: string|null}
     */
    public function issueToken(string $identifier, string $password, string $deviceName, ?int $tenantId = null): array
    {
        return $this->withToken($this->resolve($identifier, $password, $tenantId), $deviceName);
    }

    /** @return array{tenant: Tenant|null, homes: Collection<int, Tenant>, token: string|null} */
    public function issueTokenForGoogle(string $idToken, string $deviceName, ?int $tenantId = null): array
    {
        return $this->withToken($this->resolveGoogle($idToken, $tenantId), $deviceName);
    }

    /** Revoke the token the request was made with (tenant portal apps). */
    public function revokeToken(Tenant $tenant): void
    {
        $token = $tenant->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }

    /** Logs out the tenant guard only; an admin session in the same browser stays logged in. */
    public function logout(): void
    {
        Auth::guard('tenant')->logout();
    }

    /**
     * One match logs straight in; several (one person, several landlords) need a `tenant_id` choice.
     *
     * @param  Collection<int, Tenant>  $matches
     * @return array{tenant: Tenant|null, homes: Collection<int, Tenant>}
     */
    private function pick(Collection $matches, ?int $tenantId): array
    {
        if ($tenantId === null && $matches->count() > 1) {
            return ['tenant' => null, 'homes' => $matches];
        }

        $tenant = $tenantId === null ? $matches->first() : $matches->firstWhere('id', $tenantId);

        if (! $tenant) {
            throw ValidationException::withMessages(['tenant_id' => 'Choose one of your homes.']);
        }

        return ['tenant' => $tenant, 'homes' => collect()];
    }

    /**
     * @param  array{tenant: Tenant|null, homes: Collection<int, Tenant>}  $result
     * @return array{tenant: Tenant|null, homes: Collection<int, Tenant>}
     */
    private function startSession(array $result, bool $remember): array
    {
        if ($result['tenant']) {
            $this->loginTenant($result['tenant'], $remember);
        }

        return $result;
    }

    /** Cookie-session login for a tenant already identified (e.g. right after accepting an invitation). */
    public function loginTenant(Tenant $tenant, bool $remember = false): void
    {
        Auth::guard('tenant')->login($tenant, $remember);
        $this->recordLogin($tenant);
    }

    /** API token for a tenant already identified (apps). */
    public function issueTokenFor(Tenant $tenant, string $deviceName): string
    {
        $token = $tenant->createToken($deviceName, ['portal']);
        $this->recordLogin($tenant);

        return $token->plainTextToken;
    }

    /**
     * @param  array{tenant: Tenant|null, homes: Collection<int, Tenant>}  $result
     * @return array{tenant: Tenant|null, homes: Collection<int, Tenant>, token: string|null}
     */
    private function withToken(array $result, string $deviceName): array
    {
        if (! $result['tenant']) {
            return [...$result, 'token' => null];
        }

        return [...$result, 'token' => $this->issueTokenFor($result['tenant'], $deviceName)];
    }

    private function recordLogin(Tenant $tenant): void
    {
        $this->context->actingAs($tenant->account_id, fn () => $tenant->forceFill(['last_login_at' => now()])->save());
    }
}
