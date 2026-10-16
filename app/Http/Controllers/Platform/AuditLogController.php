<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditEntryResource;
use App\Services\Audit\AuditLogService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Platform', weight: 20)]
class AuditLogController extends Controller
{
    /**
     * Activity log.
     *
     * Every accepted change and every login, across all accounts, newest first. Passwords, tokens and
     * secrets are never stored.
     */
    public function index(Request $request, AuditLogService $audit): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'account_id' => ['nullable', 'integer'],
            /** Name of who did it. */
            'actor' => ['nullable', 'string', 'max:100'],
            /** e.g. payment.submitted (see the actions list) */
            'action' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            /** Search summary or name. */
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return AuditEntryResource::collection($audit->search($filters));
    }

    /**
     * Activity log actions.
     *
     * The actions that appear in the log, with labels, for a filter.
     */
    public function actions(AuditLogService $audit): JsonResponse
    {
        return response()->json(['data' => $audit->actions()]);
    }
}
