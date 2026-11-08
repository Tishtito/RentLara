<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One rent change on a lease: from old_rent to new_rent, by whom and why (LeaseService::changeRent). */
class LeaseRentChange extends Model
{
    use BelongsToAccount;

    protected $fillable = ['account_id', 'lease_id', 'old_rent', 'new_rent', 'reason', 'changed_by'];

    protected function casts(): array
    {
        return [
            'old_rent' => 'decimal:2',
            'new_rent' => 'decimal:2',
        ];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
