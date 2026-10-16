<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One message on one channel: "bill INV-2026-000123 by SMS to +2547…: delivered".
 */
class NotificationDelivery extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id', 'tenant_id', 'notifiable_type', 'notifiable_id', 'type', 'channel', 'recipient',
        'status', 'provider', 'provider_message_id', 'attempts', 'error', 'sent_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
