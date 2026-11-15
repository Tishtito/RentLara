<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A lease excused from one charge for bills on billing days starts_on … ends_on (null = open). */
class LeaseChargeExclusion extends Model
{
    use BelongsToAccount;

    protected $fillable = ['account_id', 'lease_id', 'charge_code', 'starts_on', 'ends_on', 'reason', 'created_by', 'ended_by'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /** Does it apply to a bill on this billing day? */
    public function isActiveOn(CarbonInterface $day): bool
    {
        $d = $day->toDateString();

        return $this->starts_on->toDateString() <= $d && ($this->ends_on === null || $this->ends_on->toDateString() >= $d);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }
}
