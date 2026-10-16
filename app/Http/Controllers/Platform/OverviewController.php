<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditEntryResource;
use App\Services\Platform\PlatformOverview;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Platform', weight: 20)]
class OverviewController extends Controller
{
    /**
     * Platform overview.
     *
     * Totals across every landlord account (accounts, units, this month's bills and payments, money
     * outstanding), what needs attention (failed messages, billing runs with skips, WhatsApp numbers
     * that stopped working, failed queue jobs) and the latest activity.
     */
    public function __invoke(PlatformOverview $overview): JsonResponse
    {
        $summary = $overview->summary();
        $summary['recent_activity'] = AuditEntryResource::collection($summary['recent_activity']);

        return response()->json(['data' => $summary]);
    }
}
