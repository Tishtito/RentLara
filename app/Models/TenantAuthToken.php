<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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

    /**
     * Create a token and return [model, rawToken]. Any earlier unused token of the
     * same purpose is expired, so only the newest link works.
     */
    public static function issue(Tenant $tenant, string $purpose, int $validForHours = 72, ?User $createdBy = null): array
    {
        static::query()
            ->where('tenant_id', $tenant->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->update(['expires_at' => now()]);

        $raw = Str::random(64);

        $token = static::create([
            'account_id' => $tenant->account_id,
            'tenant_id' => $tenant->id,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $raw),
            'created_by' => $createdBy?->id,
            'expires_at' => now()->addHours($validForHours),
        ]);

        return [$token, $raw];
    }

    /** Look up a raw token from a link. Runs before any login, so across all accounts. */
    public static function findValid(string $raw, string $purpose): ?self
    {
        return app(CurrentAccount::class)->withoutScope(fn () => static::query()
            ->with('tenant')
            ->where('token_hash', hash('sha256', $raw))
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first());
    }

    public function markUsed(): void
    {
        app(CurrentAccount::class)->withoutScope(fn () => $this->forceFill(['used_at' => now()])->save());
    }
}
