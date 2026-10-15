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

    /** Record a lease the job could not bill, e.g. a missing water reading. */
    public function recordSkip(Lease $lease, string $reason): void
    {
        $errors = $this->errors ?? [];
        $errors[] = ['lease_id' => $lease->id, 'unit_id' => $lease->unit_id, 'reason' => $reason];

        $this->errors = $errors;
        $this->leases_skipped++;
        $this->save();
    }

    public function finish(): void
    {
        $this->status = $this->leases_skipped > 0 ? 'completed_with_errors' : 'completed';
        $this->finished_at = now();
        $this->save();
    }
}
