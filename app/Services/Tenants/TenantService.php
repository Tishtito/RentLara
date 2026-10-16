<?php

namespace App\Services\Tenants;

use App\Enums\TenantStatus;
use App\Models\Account;
use App\Models\Tenant;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Collection;

/** Tenants are scoped per landlord account (confirmed decision #1). */
class TenantService
{
    public function __construct(private CurrentAccount $context) {}

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
