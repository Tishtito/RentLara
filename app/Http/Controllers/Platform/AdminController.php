<?php

namespace App\Http\Controllers\Platform;

use App\Events\Commands\PlatformAdminStatusRequested;
use App\Events\Commands\PlatformAdminSubmitted;
use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformAdminService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Platform', weight: 20)]
class AdminController extends Controller
{
    /**
     * List platform admins.
     */
    public function index(Request $request, PlatformAdminService $admins): JsonResponse
    {
        return response()->json(['data' => $admins->list()->map(fn ($u) => $u->only('id', 'name', 'email', 'phone', 'status', 'last_login_at', 'created_at') + ['is_me' => $u->id === $request->user()->id])]);
    }

    /**
     * Invite a platform admin.
     *
     * They get an email to set a password, then have full platform access.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        PlatformAdminSubmitted::dispatch((string) $request->input('name'), (string) $request->input('email'));

        return response()->json(['message' => 'Invitation sent.'], 202);
    }

    /**
     * Suspend or reactivate a platform admin.
     *
     * Not yourself; there is always at least one active platform admin.
     */
    public function status(Request $request, int $user): JsonResponse
    {
        $request->validate([
            /** active or suspended */
            'status' => ['required', 'in:active,suspended'],
        ]);

        PlatformAdminStatusRequested::dispatch($user, (string) $request->input('status'), $request->user()->id);

        return response()->json(['message' => 'Saved.'], 202);
    }

    /**
     * Resend an admin's invitation.
     */
    public function invite(Request $request, int $user): JsonResponse
    {
        PlatformAdminStatusRequested::dispatch($user, 'reinvite', $request->user()->id);

        return response()->json(['message' => 'Invitation sent.'], 202);
    }

    /**
     * Remove a platform admin.
     *
     * Not yourself; there is always at least one active platform admin.
     */
    public function destroy(Request $request, int $user): JsonResponse
    {
        PlatformAdminStatusRequested::dispatch($user, 'removed', $request->user()->id);

        return response()->json(['message' => 'Removed.'], 202);
    }
}
