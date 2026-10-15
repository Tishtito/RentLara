<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a bill. Readings, quantity and rate are snapshotted here so the
 * bill never changes if a reading or rate is edited later.
 *
 * No account_id column: always reached through its invoice, which is scoped.
 */
class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id', 'charge_code', 'description', 'charge_rate_id', 'meter_id',
        'previous_reading_id', 'current_reading_id', 'previous_reading', 'current_reading',
        'quantity', 'rate', 'amount', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'previous_reading' => 'decimal:2',
            'current_reading' => 'decimal:2',
            'quantity' => 'decimal:2',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function chargeRate(): BelongsTo
    {
        return $this->belongsTo(ChargeRate::class);
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }

    public function isMetered(): bool
    {
        return $this->meter_id !== null;
    }
}
