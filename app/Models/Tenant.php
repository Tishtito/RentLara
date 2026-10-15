<?php

namespace App\Models;

use App\Enums\LeaseStatus;
use App\Enums\TenantStatus;
use App\Models\Concerns\BelongsToAccount;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A renter, scoped to one landlord account (confirmed decision #1).
 * Logs into the tenant portal through the "tenant" guard, which uses the
 * AccountlessUserProvider so the session can load the tenant before any
 * account context is set.
 */
class Tenant extends Authenticatable
{
    use BelongsToAccount, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'account_id', 'first_name', 'last_name', 'email', 'national_id', 'phone', 'whatsapp_phone',
        'password', 'notification_channels', 'next_of_kin_name', 'next_of_kin_phone', 'status',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'notification_channels' => 'array',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    public function activeLease(): HasOne
    {
        return $this->hasOne(Lease::class)->ofMany(
            ['start_date' => 'max', 'id' => 'max'],
            fn ($query) => $query->whereIn('status', LeaseStatus::billable()),
        );
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function authTokens(): HasMany
    {
        return $this->hasMany(TenantAuthToken::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /** Has the tenant accepted their invitation and set a password? */
    public function hasActivatedPortal(): bool
    {
        return $this->password !== null;
    }

    /** The tenant's chosen channels, or the landlord's default. */
    public function notificationChannels(): array
    {
        return $this->notification_channels ?: $this->account->defaultNotificationChannels();
    }

    public function routeNotificationForSms(): string
    {
        return $this->phone;
    }

    public function routeNotificationForWhatsapp(): string
    {
        return $this->whatsapp_phone ?: $this->phone;
    }

    /**
     * Tenant-portal login: find every portal-enabled tenant record matching an
     * email or national ID across ALL landlords. More than one result means the
     * person rents from several landlords and the login screen asks which one.
     */
    public static function findForPortalLogin(string $identifier): Collection
    {
        $identifier = trim($identifier);
        $column = str_contains($identifier, '@') ? 'email' : 'national_id';

        return app(CurrentAccount::class)->withoutScope(fn () => static::query()
            ->with('account:id,name,status')
            ->where($column, $column === 'email' ? mb_strtolower($identifier) : $identifier)
            ->whereNotNull('password')
            ->where('status', TenantStatus::Active)
            ->get());
    }

    /** Emails are stored lower-case so login matching is case-insensitive. */
    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value === null ? null : mb_strtolower(trim($value));
    }
}
