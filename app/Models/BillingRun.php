<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingRun extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id', 'run_date', 'trigger', 'triggered_by', 'status',
        'invoices_created', 'leases_skipped', 'errors', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'run_date' => 'date',
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
