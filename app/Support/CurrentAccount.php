<?php

namespace App\Support;

use App\Models\Account;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Holds which landlord account the current request / job is acting for.
 *
 * Registered as a *scoped* singleton (see AppServiceProvider), so it resets
 * per request and per queued job.
 *
 * The AccountScope reads this:
 *   - id set      -> every account-owned query is filtered to that account
 *   - bypassing   -> no filter (super admin screens, tenant login lookup)
 *   - neither     -> queries return NOTHING (fail closed), so a forgotten
 *                    context can never leak one landlord's data to another
 */
class CurrentAccount
{
    private ?int $id = null;

    private bool $bypass = false;

    /** @var array<int, string> account id → timezone, looked up once per request */
    private array $timezones = [];

    public function set(Account|int|null $account): void
    {
        $this->id = $account instanceof Account ? $account->getKey() : $account;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    /** Today (start of day) in an account's timezone: the current account's unless one is given. */
    public function today(?int $accountId = null): CarbonImmutable
    {
        $accountId ??= $this->id;
        $timezone = $accountId === null ? config('app.timezone')
            : ($this->timezones[$accountId] ??= (Account::query()->whereKey($accountId)->value('timezone') ?: config('app.timezone')));

        return CarbonImmutable::now($timezone)->startOfDay();
    }

    public function has(): bool
    {
        return $this->id !== null;
    }

    public function isBypassing(): bool
    {
        return $this->bypass;
    }

    /** Super admins: see every account for the rest of this request. */
    public function bypass(): void
    {
        $this->bypass = true;
    }

    /** Run a callback acting as a specific account, then restore the previous state. */
    public function actingAs(Account|int $account, Closure $callback): mixed
    {
        [$prevId, $prevBypass] = [$this->id, $this->bypass];
        $this->set($account);
        $this->bypass = false;

        try {
            return $callback();
        } finally {
            [$this->id, $this->bypass] = [$prevId, $prevBypass];
        }
    }

    /** Run a callback across all accounts, then restore the previous state. */
    public function withoutScope(Closure $callback): mixed
    {
        $prevBypass = $this->bypass;
        $this->bypass = true;

        try {
            return $callback();
        } finally {
            $this->bypass = $prevBypass;
        }
    }
}
