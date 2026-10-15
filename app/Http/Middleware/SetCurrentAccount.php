<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the account context from whoever is logged in. Put it after `auth`:
 *
 *   Route::middleware(['auth:sanctum', 'account'])->group(...)        // admin portal
 *   Route::middleware(['auth:tenant', 'account'])->group(...)         // tenant portal
 *
 * Super admins get no account and bypass the scope (they see everything).
 * A super admin can act inside one landlord's account by sending X-Account-Id.
 */
class SetCurrentAccount
{
    public function __construct(private CurrentAccount $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();

        if ($actor instanceof User && $actor->isSuperAdmin()) {
            $accountId = $request->header('X-Account-Id');

            if ($accountId) {
                abort_unless(Account::query()->whereKey((int) $accountId)->exists(), 404, 'Account not found.');
                $this->context->set((int) $accountId);
            } else {
                $this->context->bypass();
            }

            return $next($request);
        }

        if (($actor instanceof User || $actor instanceof Tenant) && $actor->account_id) {
            abort_unless($actor->account?->isActive(), 403, 'This account is suspended.');
            abort_if($actor instanceof User && ! $actor->isActive(), 403, 'Your login is disabled.');

            $this->context->set($actor->account_id);

            return $next($request);
        }

        abort(403, 'No account for this login.');
    }
}
