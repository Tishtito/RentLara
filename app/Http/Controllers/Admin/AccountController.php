<?php

namespace App\Http\Controllers\Admin;

use App\Events\Commands\AccountBillingSettingsSubmitted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAccountBillingRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Support\CurrentAccount;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

#[Group('Billing settings', weight: 6)]
class AccountController extends Controller
{
    /**
     * Show the account.
     *
     * The landlord account and its billing settings. Super admins must send `X-Account-Id`.
     */
    public function show(CurrentAccount $context): AccountResource
    {
        $account = $this->current($context);
        Gate::authorize('view', $account);

        return new AccountResource($account);
    }

    /**
     * Change billing settings.
     *
     * The billing day (every lease bills on that day of the month), days until due and the bill number
     * prefix. A new billing day moves each lease's next bill to the first new billing day on or after
     * it; bills already issued don't change.
     */
    public function updateBilling(UpdateAccountBillingRequest $request, CurrentAccount $context): JsonResponse
    {
        $account = $this->current($context);
        Gate::authorize('update', $account);

        AccountBillingSettingsSubmitted::dispatch(
            $account->id,
            $request->integer('billing_day'),
            $request->integer('payment_due_days'),
            strtoupper((string) $request->input('invoice_prefix')),
        );

        return response()->json(['message' => 'Billing settings saved.'], 202);
    }

    private function current(CurrentAccount $context): Account
    {
        abort_unless($context->has(), 422, 'Choose an account with the X-Account-Id header.');

        return Account::query()->findOrFail($context->id());
    }
}
