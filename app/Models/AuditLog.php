<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of the activity log (written only by AuditLogService). Not account-scoped: the platform
 * reads across accounts, and every read filters explicitly.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'account_id', 'actor_type', 'actor_id', 'actor_name', 'via_platform', 'action', 'summary',
        'subject_type', 'subject_id', 'data', 'ip', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'via_platform' => 'boolean',
            'data' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
