<?php

namespace App\Http\Controllers\Admin;

use App\Events\Commands\LeaseEndRequested;
use App\Events\Commands\MoveInSubmitted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EndLeaseRequest;
use App\Http\Requests\Admin\MoveInRequest;
use App\Models\Lease;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

#[Group('Tenants & leases', weight: 5)]
class LeaseController extends Controller
{
    /**
     * Move a tenant in.
     *
     * One step: creates the tenant and their lease (first bill one billing cycle after `start_date`),
     * marks the unit occupied, records an opening reading for every active meter on the move-in date,
     * and emails the tenant a portal invitation if an email is given. The unit must be vacant or reserved.
     */
    public function moveIn(MoveInRequest $request, Unit $unit): JsonResponse
    {
        Gate::authorize('create', [Lease::class, $unit]);

        MoveInSubmitted::dispatch($request->toData($unit->id));

        return response()->json(['message' => 'Tenant moved in.'], 202);
    }

    /**
     * Move a tenant out.
     *
     * Ends the lease on `end_date` and the unit becomes vacant. What the tenant still owes (or their
     * credit) stays on their statement; no more bills are created for this lease.
     */
    public function end(EndLeaseRequest $request, Lease $lease): JsonResponse
    {
        Gate::authorize('end', $lease);

        LeaseEndRequested::dispatch($lease->id, CarbonImmutable::parse($request->input('end_date')));

        return response()->json(['message' => 'Lease ended.'], 202);
    }
}
