<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use App\Support\CurrentAccount;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group('Dashboard', weight: 1)]
class DashboardController extends Controller
{
    /**
     * Dashboard.
     *
     * This billing month (from the latest billing day to today): billed, collected and the collection rate;
     * arrears across running leases; occupancy; what needs attention (readings missing for bills due in
     * the next 2 days, overdue bills, failed messages); bills due in the next 7 days with their readings
     * status; billed vs collected per property. Caretakers see their properties. Super admins need
     * `X-Account-Id`.
     */
    public function __invoke(Request $request, DashboardService $dashboard, CurrentAccount $context): JsonResponse
    {
        Gate::authorize(Permission::DashboardView->value);
        abort_unless($context->has(), 422, 'Choose an account with the X-Account-Id header.');

        return response()->json(['data' => $dashboard->summary($request->user(), $context->id())]);
    }
}
