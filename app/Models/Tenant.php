<?php

namespace App\Models;

use App\Enums\LeaseStatus;
use App\Enums\TenantStatus;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A renter, scoped to one landlord account (confirmed decision #1).
 * Logs into the tenant portal through the "tenant" guard, which uses the
 * AccountlessUserProvider so the session can load the tenant before any
 * account context is set. The tenant portal apps (Capacitor) log in with Sanctum API tokens
 * instead (ability `portal`).
 */
class Tenant extends Authenticatable
{
    use BelongsToAccount, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

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

    public function routeNotificationForSms(): string
    {
        return $this->phone;
    }

    public function routeNotificationForWhatsapp(): string
    {
        return $this->whatsapp_phone ?: $this->phone;
    }
}
