<?php

namespace App\Models\Concerns;

use App\Models\Account;
use App\Models\Scopes\AccountScope;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Use on every landlord-owned model (anything with an account_id column).
 *
 *  - Reads are filtered to the current account (see AccountScope).
 *  - New rows get account_id filled in automatically.
 *  - Saving a row for a different account than the current one throws,
 *    which catches bugs like a forged property_id in a form post early.
 */
trait BelongsToAccount
{
    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope(new AccountScope);

        static::creating(function ($model) {
            $context = app(CurrentAccount::class);

            if (empty($model->account_id) && $context->has()) {
                $model->account_id = $context->id();
            }

            if (empty($model->account_id)) {
                throw new LogicException(static::class.' cannot be created without an account_id.');
            }
        });

        // Laravel fires `saving` before `creating`, so a new row may not have its
        // account_id yet; the creating hook above fills and checks it.
        static::saving(function ($model) {
            $context = app(CurrentAccount::class);

            if ($context->has() && ! $context->isBypassing() && ! empty($model->account_id)
                && (int) $model->account_id !== $context->id()) {
                throw new LogicException(static::class.' belongs to another account.');
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Explicitly query another account (super admin tools, the billing job). */
    public function scopeForAccount(Builder $query, Account|int $account): Builder
    {
        $id = $account instanceof Account ? $account->getKey() : $account;

        return $query->withoutGlobalScope(AccountScope::class)
            ->where($this->qualifyColumn('account_id'), $id);
    }
}
