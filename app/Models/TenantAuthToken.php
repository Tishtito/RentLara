<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One-time invitation / password-reset links for the tenant portal.
 * Only the SHA-256 hash is stored; the raw token goes out in the link.
 */
class TenantAuthToken extends Model
{
    use BelongsToAccount;

    public const PURPOSE_INVITATION = 'invitation';

    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    protected $fillable = [
        'account_id', 'tenant_id', 'purpose', 'token_hash', 'sent_via', 'created_by', 'expires_at', 'used_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
