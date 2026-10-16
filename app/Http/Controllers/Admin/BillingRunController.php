<?php

namespace App\Http\Controllers\Admin;

use App\Events\Commands\BillingRunRequested;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBillingRunRequest;
use App\Http\Resources\BillingRunResource;
use App\Models\BillingRun;
use App\Services\Billing\BillingService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

#[Group('Billing runs', weight: 1)]
class BillingRunController extends Controller
{
    /**
     * List billing runs.
     *
     * Newest first. Each run shows how many bills it created and which leases it skipped and why
     * (e.g. a missing meter reading) in `errors`.
     */
    public function index(BillingService $billing): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', BillingRun::class);

        return BillingRunResource::collection($billing->listRuns());
    }

    /**
     * Start a billing run.
     *
     * Bills every lease whose 30-day cycle has ended, as of `run_date` (default today), and emails
     * each bill to its tenant. Runs automatically every day at 06:00 Nairobi time; use this to run
     * it now. Safe to repeat: leases already billed for the period are not billed again.
     * Landlords only; super admins must send `X-Account-Id`.
     *
     * The run happens in the background: list billing runs to see the result.
     */
    public function store(StoreBillingRunRequest $request): JsonResponse
    {
        Gate::authorize('create', BillingRun::class);

        BillingRunRequested::dispatch($request->toData());

        return response()->json(['message' => 'Billing run queued.'], 202);
    }
}
