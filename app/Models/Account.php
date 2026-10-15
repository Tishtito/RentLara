<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A landlord business. Not account-scoped itself: it IS the scope.
 */
class Account extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'email', 'phone', 'currency', 'timezone',
        'invoice_prefix', 'billing_cycle_days', 'payment_due_days', 'status', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'billing_cycle_days' => 'integer',
            'payment_due_days' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function billingRuns(): HasMany
    {
        return $this->hasMany(BillingRun::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Default channels for tenants who haven't set their own preference. */
    public function defaultNotificationChannels(): array
    {
        return $this->settings['notification_channels'] ?? ['sms', 'email'];
    }
}
