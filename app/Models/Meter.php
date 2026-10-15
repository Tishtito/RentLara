<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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

    /** Latest reading on or before a date (the "current reading" on a bill). */
    public function readingOnOrBefore(CarbonInterface|string $date): ?MeterReading
    {
        return $this->readings()
            ->whereDate('reading_date', '<=', Carbon::parse($date)->toDateString())
            ->orderByDesc('reading_date')
            ->first();
    }

    /** Latest reading strictly before a date (the "previous reading" on a bill). */
    public function readingBefore(CarbonInterface|string $date): ?MeterReading
    {
        return $this->readings()
            ->whereDate('reading_date', '<', Carbon::parse($date)->toDateString())
            ->orderByDesc('reading_date')
            ->first();
    }
}
