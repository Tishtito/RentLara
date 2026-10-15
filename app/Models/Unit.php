<?php

namespace App\Models;

use App\Enums\LeaseStatus;
use App\Enums\UnitStatus;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use BelongsToAccount, HasFactory, SoftDeletes;

    protected $fillable = [
        'account_id', 'property_id', 'unit_number', 'type', 'bedrooms', 'floor',
        'default_rent', 'default_deposit', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => UnitStatus::class,
            'default_rent' => 'decimal:2',
            'default_deposit' => 'decimal:2',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function meters(): HasMany
    {
        return $this->hasMany(Meter::class);
    }

    public function activeMeters(): HasMany
    {
        return $this->meters()->where('is_active', true);
    }

    /** Per-unit rate overrides (property-wide rates live on the property). */
    public function chargeRateOverrides(): HasMany
    {
        return $this->hasMany(ChargeRate::class);
    }

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    public function currentLease(): HasOne
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
}
