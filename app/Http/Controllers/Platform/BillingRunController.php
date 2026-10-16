<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformBillingRunResource;
use App\Services\Platform\PlatformBilling;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Platform', weight: 20)]
class BillingRunController extends Controller
{
    /**
     * List billing runs (all accounts).
     *
     * Newest first. `problems=1` shows only runs that failed or skipped leases.
     */
    public function index(Request $request, PlatformBilling $billing): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'account_id' => ['nullable', 'integer'],
            /** running, completed, completed_with_errors or failed */
            'status' => ['nullable', 'in:running,completed,completed_with_errors,failed'],
            'problems' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return PlatformBillingRunResource::collection($billing->runs($filters + ['problems' => $request->boolean('problems')]));
    }

    /**
     * Show a billing run.
     *
     * With each skipped lease's property, unit, tenant and the reason (e.g. a missing meter reading).
     */
    public function show(int $run, PlatformBilling $billing): JsonResponse
    {
        $detail = $billing->run($run);

        return response()->json(['data' => (new PlatformBillingRunResource($detail['run']))->resolve() + ['skipped' => $detail['skipped']]]);
    }
}
