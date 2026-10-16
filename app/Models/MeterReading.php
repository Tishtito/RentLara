<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
