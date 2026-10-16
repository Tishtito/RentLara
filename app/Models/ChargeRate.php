<?php

namespace App\Models;

use App\Enums\BillingType;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Landlord billing settings: electricity / water price per unit, garbage fee, etc.
 *
 * VERSIONED, NEVER EDITED: once a rate row exists, only effective_to may change.
 * All writes go through App\Services\Rates\ChargeRateService::change(), which closes
 * the current row the day before the new one starts. Old bills therefore always match their rate.
 */
class ChargeRate extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id', 'property_id', 'unit_id', 'charge_code', 'label', 'billing_type',
        'rate', 'unit_of_measure', 'effective_from', 'effective_to', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'billing_type' => BillingType::class,
            'rate' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** Who set this price (shown in the rate history). */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isMetered(): bool
    {
        return $this->billing_type === BillingType::Metered;
    }
}
