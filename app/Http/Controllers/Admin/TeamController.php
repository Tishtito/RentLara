<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Events\Commands\StaffStatusRequested;
use App\Events\Commands\StaffSubmitted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffRequest;
use App\Http\Resources\TeamMemberResource;
use App\Services\Team\TeamService;
use App\Support\CurrentAccount;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

#[Group('Team & roles', weight: 8)]
class TeamController extends Controller
{
    /**
     * List the team.
     *
     * Everyone who works in this account, with their roles, the properties they look after and whether
     * they've set up their login.
     */
    public function index(TeamService $team, CurrentAccount $context): AnonymousResourceCollection
    {
        Gate::authorize(Permission::TeamView->value);

        return TeamMemberResource::collection($team->members($this->account($context)));
    }

    /**
     * Invite a team member.
     *
     * Creates their login with the given roles and emails them a link to set a password (valid 3 days).
     */
    public function store(StaffRequest $request, CurrentAccount $context): JsonResponse
    {
        Gate::authorize(Permission::TeamManage->value);
        $this->account($context);

        StaffSubmitted::dispatch($request->toData());

        return response()->json(['message' => 'Invitation sent.'], 202);
    }

    /**
     * Change a team member.
     *
     * Name, phone, roles and the properties they look after. You can't change your own roles, and
     * the account must keep an active Owner.
     */
    public function update(StaffRequest $request, int $user, CurrentAccount $context): JsonResponse
    {
        Gate::authorize(Permission::TeamManage->value);
        $this->account($context);

        StaffSubmitted::dispatch($request->toData($user));

        return response()->json(['message' => 'Saved.'], 202);
    }

    /**
     * Suspend or reactivate a team member.
     *
     * Suspended members can't log in (their app tokens are revoked). Not yourself, not the last Owner.
     */
    public function status(Request $request, int $user, CurrentAccount $context): JsonResponse
    {
        Gate::authorize(Permission::TeamManage->value);
        $request->validate([
            /** active or suspended */
            'status' => ['required', 'in:active,suspended'],
        ]);

        StaffStatusRequested::dispatch($this->account($context), $user, (string) $request->input('status'), $request->user()->id);

        return response()->json(['message' => 'Saved.'], 202);
    }

    /**
     * Send the invitation again.
     *
     * For members who haven't set their password yet; the old link stops working.
     */
    public function invite(Request $request, int $user, CurrentAccount $context): JsonResponse
    {
        Gate::authorize(Permission::TeamManage->value);

        StaffStatusRequested::dispatch($this->account($context), $user, 'reinvite', $request->user()->id);

        return response()->json(['message' => 'Invitation sent.'], 202);
    }

    /**
     * Remove a team member.
     *
     * Their login stops working and the properties they looked after have nobody assigned. Not yourself,
     * not the last Owner.
     */
    public function destroy(Request $request, int $user, CurrentAccount $context): JsonResponse
    {
        Gate::authorize(Permission::TeamManage->value);

        StaffStatusRequested::dispatch($this->account($context), $user, 'removed', $request->user()->id);

        return response()->json(['message' => 'Removed.'], 202);
    }

    private function account(CurrentAccount $context): int
    {
        abort_unless($context->has(), 422, 'Choose an account with the X-Account-Id header.');

        return (int) $context->id();
    }
}
