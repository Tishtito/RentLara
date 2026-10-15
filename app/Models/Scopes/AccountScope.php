<?php

namespace App\Models\Scopes;

use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class AccountScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CurrentAccount::class);

        if ($context->isBypassing()) {
            return;
        }

        if ($context->has()) {
            $builder->where($model->qualifyColumn('account_id'), $context->id());

            return;
        }

        // No account context: fail closed rather than return every landlord's rows.
        $builder->whereRaw('1 = 0');
    }
}
