<?php

namespace App\Http\Controllers\Platform;

use App\Events\Commands\AccountCreationSubmitted;
use App\Events\Commands\AccountProfileSubmitted;
use App\Events\Commands\AccountStatusRequested;
use App\Events\Commands\BillingRunRequested;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\CreateAccountRequest;
use App\Http\Requests\Platform\RunBillingRequest;
use App\Http\Requests\Platform\UpdateAccountRequest;
use App\Http\Resources\AuditEntryResource;
use App\Services\Platform\PlatformAccounts;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Platform', weight: 20)]
class AccountController extends Controller
{
    /**
     * List landlord accounts.
     *
     * Every account with its owner, size, last billing run and WhatsApp status. Search by account,
     * owner, phone or email. To work inside one, call the admin API with `X-Account-Id: {id}`.
     */
    public function index(Request $request, PlatformAccounts $accounts): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            /** active, suspended or closed */
            'status' => ['nullable', 'in:active,suspended,closed'],
        ]);

        $page = $accounts->list($request->only('search', 'status'));

        return response()->json([
            'data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Show a landlord account.
     *
     * Details and billing settings, counts, money outstanding, the team, recent billing runs, messages
     * in the last 7 days, the WhatsApp number and the latest activity in the account.
     */
    public function show(int $account, PlatformAccounts $accounts): JsonResponse
    {
        $detail = $accounts->detail($account);
        $detail['activity'] = AuditEntryResource::collection($detail['activity']);

        return response()->json(['data' => $detail]);
    }

    /**
     * Create a landlord account.
     *
     * Creates the account and invites its owner by email to set a password (link valid 3 days).
     */
    public function store(CreateAccountRequest $request): JsonResponse
    {
        AccountCreationSubmitted::dispatch($request->toData());

        return response()->json(['message' => 'Account created. The owner has been emailed.'], 202);
    }

    /**
     * Edit a landlord account.
     *
     * Name, contact details, time zone and billing settings (they apply from each lease's next bill).
     */
    public function update(UpdateAccountRequest $request, int $account): JsonResponse
    {
        AccountProfileSubmitted::dispatch($request->toData($account));

        return response()->json(['message' => 'Saved.'], 202);
    }

    /**
     * Suspend, close or reactivate an account.
     *
     * Suspended and closed accounts can't log in (staff or tenants), their app tokens are revoked and
     * they aren't billed. Nothing is deleted; reactivate to restore access.
     */
    public function status(Request $request, int $account): JsonResponse
    {
        $request->validate([
            /** active, suspended or closed */
            'status' => ['required', 'in:active,suspended,closed'],
            /** Why (kept in the activity log). */
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        AccountStatusRequested::dispatch($account, (string) $request->input('status'), $request->input('reason'));

        return response()->json(['message' => 'Saved.'], 202);
    }

    /**
     * Run billing for an account.
     *
     * Same as the daily 06:00 run, for one active account, as of `run_date` (default today). Safe to
     * repeat. Runs in the background: see the account's billing runs for the result.
     */
    public function bill(RunBillingRequest $request): JsonResponse
    {
        BillingRunRequested::dispatch($request->toData());

        return response()->json(['message' => 'Billing run queued.'], 202);
    }
}
