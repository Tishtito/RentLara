<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class MeterReading extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id', 'meter_id', 'reading_date', 'reading', 'photo_path', 'recorded_by', 'is_estimated',
    ];

    protected function casts(): array
    {
        return [
            'reading_date' => 'date',
            'reading' => 'decimal:2',
            'is_estimated' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // A meter only counts up. A new physical meter gets a new meters row instead.
        static::saving(function (MeterReading $reading) {
            $previous = static::query()
                ->where('meter_id', $reading->meter_id)
                ->whereDate('reading_date', '<', $reading->reading_date->toDateString())
                ->orderByDesc('reading_date')
                ->value('reading')
                ?? $reading->meter?->initial_reading;

            if ($previous !== null && (float) $reading->reading < (float) $previous) {
                throw ValidationException::withMessages([
                    'reading' => "Reading {$reading->reading} is lower than the previous reading {$previous}.",
                ]);
            }
        });
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
