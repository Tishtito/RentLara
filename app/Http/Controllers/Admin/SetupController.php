<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Services\Accounts\AccountSetupProgress;
use App\Support\CurrentAccount;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

#[Group('Dashboard', weight: 1)]
class SetupController extends Controller
{
    /**
     * Getting started.
     *
     * The setup checklist for a new landlord: property, prices, units, meters, billing day and tenants,
     * each with whether it's done and the admin page to do it on.
     */
    public function __invoke(AccountSetupProgress $progress, CurrentAccount $context): JsonResponse
    {
        Gate::authorize(Permission::DashboardView->value);
        abort_unless($context->has(), 422, 'Choose an account with the X-Account-Id header.');

        return response()->json(['data' => $progress->for((int) $context->id())]);
    }
}
