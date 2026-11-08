<?php

namespace App\Http\Controllers\Admin;

use App\Events\Commands\LeaseBillRequested;
use App\Events\Commands\LeaseEndRequested;
use App\Events\Commands\LeaseRentChangeSubmitted;
use App\Events\Commands\MoveInSubmitted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeRentRequest;
use App\Http\Requests\Admin\EndLeaseRequest;
use App\Http\Requests\Admin\MoveInRequest;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Unit;
use App\Services\Notifications\MessagePlan;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group('Tenants & leases', weight: 5)]
class LeaseController extends Controller
{
    /**
     * Move a tenant in.
     *
     * One step: creates the tenant and their lease (first bill one billing cycle after `start_date`),
     * marks the unit occupied, records an opening reading for every active meter on the move-in date,
     * and emails the tenant a portal invitation if an email is given (plus WhatsApp when connected).
     * The unit must be vacant or reserved. `messages` lists where the invitation goes, per channel
     * (empty without an email).
     */
    public function moveIn(MoveInRequest $request, Unit $unit, MessagePlan $plan): JsonResponse
    {
        Gate::authorize('create', [Lease::class, $unit]);

        MoveInSubmitted::dispatch($request->toData($unit->id));

        $tenant = $request->input('tenant.email') ? $unit->currentLease()->first()?->tenant()->first() : null;

        return response()->json(['message' => 'Tenant moved in.', 'messages' => $tenant ? $plan->forInvitation($tenant) : []], 202);
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

    /**
     * Bill now.
     *
     * Makes this tenant's due monthly bill straight away, without a full billing run: e.g. after the run
     * skipped it for a missing reading that's now entered, or the unit switched to a fixed amount. Issued
     * today, for the period that's due (e.g. 5 Nov – 4 Dec), and sent like every bill. One period at a time.
     * 422 when nothing is due yet (`bill_due` false), the tenant moved out, a reading is still missing, or
     * it's already billed. Returns the new bill's `invoice_id`; `messages` lists where it's sent.
     */
    public function bill(Request $request, Lease $lease, MessagePlan $plan): JsonResponse
    {
        Gate::authorize('bill', $lease);

        LeaseBillRequested::dispatch($lease->id, $request->user()->id);

        $invoice = Invoice::query()->where('lease_id', $lease->id)->latest('id')->firstOrFail();

        return response()->json([
            'message' => 'Bill made.',
            /** The new bill. */
            'invoice_id' => $invoice->id,
            'messages' => $plan->forTenant($lease->tenant()->firstOrFail()),
        ], 202);
    }

    /**
     * Change the rent.
     *
     * Sets a new monthly rent on a running lease. It applies from the next bill (including any
     * billing-day gap line); bills already issued keep their rent. To correct a bill that's already
     * been sent, re-bill it (`POST invoices/{id}/rebill`). Each change is kept in the rent history.
     */
    public function changeRent(ChangeRentRequest $request, Lease $lease): JsonResponse
    {
        Gate::authorize('update', $lease);

        LeaseRentChangeSubmitted::dispatch($lease->id, $request->monthlyRent(), $request->input('reason'), $request->user()->id);

        return response()->json(['message' => 'Rent changed.'], 202);
    }

    /**
     * Rent history.
     *
     * Every rent change on the lease, newest first, with who made it and why.
     */
    public function rentChanges(Lease $lease): JsonResponse
    {
        Gate::authorize('view', $lease);

        $changes = $lease->rentChanges()->with('changedBy:id,name')->get()->map(fn ($c) => [
            'id' => $c->id,
            'old_rent' => $c->old_rent,
            'new_rent' => $c->new_rent,
            'reason' => $c->reason,
            'changed_by' => $c->changedBy?->name,
            'created_at' => $c->created_at,
        ]);

        return response()->json(['data' => $changes]);
    }
}
