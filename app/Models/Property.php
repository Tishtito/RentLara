<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use BelongsToAccount, HasFactory, SoftDeletes;

    protected $fillable = [
        'account_id', 'name', 'code', 'address', 'town', 'county', 'caretaker_id', 'status', 'notes', 'billing_day',
    ];

    protected function casts(): array
    {
        return [
            'billing_day' => 'integer', // null = the account's billing day; units can override it
        ];
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function chargeRates(): HasMany
    {
        return $this->hasMany(ChargeRate::class);
    }

    /** Property-wide rates only (not per-unit overrides). */
    public function defaultChargeRates(): HasMany
    {
        return $this->chargeRates()->whereNull('unit_id');
    }

    public function caretaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caretaker_id');
    }

    public function leases(): HasManyThrough
    {
        return $this->hasManyThrough(Lease::class, Unit::class);
    }
}
