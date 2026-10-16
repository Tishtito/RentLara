<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meter extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id', 'unit_id', 'charge_code', 'meter_number', 'initial_reading', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'initial_reading' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function readings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }
}
