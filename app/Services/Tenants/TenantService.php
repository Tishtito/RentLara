<?php

namespace App\Services\Tenants;

use App\Enums\TenantStatus;
use App\Events\TenantPasswordChanged;
use App\Models\Account;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\TenantAuthToken;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Tenants are scoped per landlord account (confirmed decision #1). */
class TenantService
{
    public function __construct(
        private CurrentAccount $context,
        private TenantAuthTokenService $authTokens,
    ) {}

    public function create(array $attributes): Tenant
    {
        if (array_key_exists('email', $attributes)) {
            $attributes['email'] = $this->normalizeEmail($attributes['email']);
        }

        return Tenant::create($attributes);
    }

    /** Update contact details (from the admin portal). */
    public function update(Tenant $tenant, array $attributes): Tenant
    {
        if (array_key_exists('email', $attributes)) {
            $attributes['email'] = $this->normalizeEmail($attributes['email']);
        }

        $tenant->fill($attributes)->save();

        return $tenant;
    }

    /**
     * The landlord sets a new portal password (and tells the tenant). Unused reset links stop working and
     * the tenant's apps are logged out.
     *
     * @throws ValidationException the tenant hasn't set up their login yet (send the invitation instead)
     */
    public function setPassword(Tenant $tenant, string $password): Tenant
    {
        if ($tenant->status !== TenantStatus::Active || ! $tenant->hasActivatedPortal()) {
            throw ValidationException::withMessages(['tenant' => 'This tenant hasn\'t set up their login yet. Send the invitation instead.']);
        }

        $tenant->forceFill(['password' => $password])->save();
        $this->authTokens->expireAll($tenant, TenantAuthToken::PURPOSE_PASSWORD_RESET);
        $this->revokeAppTokens($tenant);
        TenantPasswordChanged::dispatch($tenant, 'landlord');

        return $tenant;
    }

    /**
     * The logged-in tenant changes their own password. Their other app logins are signed out; the one
     * making the request (`$keepTokenId`) stays logged in.
     *
     * @throws ValidationException wrong current password
     */
    public function changePassword(Tenant $tenant, string $current, string $new, ?int $keepTokenId = null): Tenant
    {
        if (! $tenant->password || ! Hash::check($current, $tenant->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is not right.']);
        }

        $tenant->forceFill(['password' => $new])->save();
        $this->authTokens->expireAll($tenant, TenantAuthToken::PURPOSE_PASSWORD_RESET);
        $this->revokeAppTokens($tenant, $keepTokenId);
        TenantPasswordChanged::dispatch($tenant, 'tenant');

        return $tenant;
    }

    /** Log the tenant out of the Android/iOS apps (API tokens), except `$keepTokenId`. */
    public function revokeAppTokens(Tenant $tenant, ?int $keepTokenId = null): void
    {
        PersonalAccessToken::query()
            ->where('tokenable_type', $tenant->getMorphClass())
            ->where('tokenable_id', $tenant->id)
            ->when($keepTokenId, fn ($q, $id) => $q->whereKeyNot($id))
            ->delete();
    }

    /**
     * Tenant-portal login: every portal-enabled tenant record matching an email or national
     * ID across ALL landlords. More than one result means the person rents from several
     * landlords and the login screen asks which one.
     */
    public function findForPortalLogin(string $identifier): Collection
    {
        $identifier = trim($identifier);
        $column = str_contains($identifier, '@') ? 'email' : 'national_id';
        $value = $column === 'email' ? $this->normalizeEmail($identifier) : $identifier;

        return $this->context->withoutScope(fn () => Tenant::query()
            ->with('account:id,name,status')
            ->where($column, $value)
            ->whereNotNull('password')
            ->where('status', TenantStatus::Active)
            ->get());
    }

    /** The tenant's chosen channels, or the landlord's default (email + WhatsApp). */
    public function notificationChannels(Tenant $tenant): array
    {
        if ($tenant->notification_channels) {
            return $tenant->notification_channels;
        }

        $settings = Account::query()->whereKey($tenant->account_id)->value('settings') ?? [];

        return $settings['notification_channels'] ?? ['email', 'whatsapp'];
    }

    /** Emails are stored lower-case so portal login matching is case-insensitive. */
    public function normalizeEmail(?string $email): ?string
    {
        return $email === null ? null : mb_strtolower(trim($email));
    }
}
