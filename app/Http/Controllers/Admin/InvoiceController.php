<?php

namespace App\Http\Controllers\Admin;

use App\Enums\NotificationChannel;
use App\Events\Commands\InvoiceResendRequested;
use App\Events\Commands\InvoiceVoidRequested;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\Billing\InvoiceDirectory;
use App\Services\Billing\InvoicePdf;
use App\Services\Notifications\MessagePlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    /**
     * List bills.
     *
     * Newest first, limited to the properties you can see. `status=unpaid` means issued, partly paid or
     * overdue. `meta.summary` totals the bills matching the filters (void bills add no money).
     */
    public function index(Request $request, InvoiceDirectory $bills): JsonResponse
    {
        Gate::authorize('viewAny', Invoice::class);
        $filters = $request->validate([
            /** Bill number, tenant name or phone, or unit. */
            'search' => ['nullable', 'string', 'max:100'],
            /** unpaid, issued, partially_paid, overdue, paid or void */
            'status' => ['nullable', 'in:unpaid,issued,partially_paid,overdue,paid,void'],
            'property_id' => ['nullable', 'integer'],
            /**
             * Bills issued in this month.
             *
             * @example 2026-10
             */
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $page = $bills->list($request->user(), $filters);

        return InvoiceResource::collection($page)->additional(['meta' => ['summary' => $bills->summary($request->user(), $filters)]])->response();
    }

    /**
     * Show an invoice.
     *
     * One bill with its lines. Metered lines carry the readings and rate they were charged at.
     * `balance_brought_forward` is the arrears line, `total_due` = `current_charges` + arrears,
     * and `balance_remaining` is what's still owed on this bill. `deliveries` lists every time it
     * was sent (email / WhatsApp) and what happened.
     */
    public function show(Invoice $invoice): InvoiceResource
    {
        Gate::authorize('view', $invoice);

        return new InvoiceResource($invoice->load(['items', 'tenant', 'unit.property', 'voidedBy:id,name', 'notificationDeliveries' => fn ($q) => $q->latest('id')]));
    }

    /**
     * Download a bill (PDF).
     *
     * The same PDF the tenant receives.
     */
    public function pdf(Invoice $invoice, InvoicePdf $pdf): Response
    {
        Gate::authorize('view', $invoice);
        $invoice->load(['items', 'tenant', 'unit.property', 'account']);

        return response($pdf->render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($invoice).'"',
        ]);
    }

    /**
     * Resend a bill.
     *
     * Sends the bill again by email and/or WhatsApp (default: the tenant's usual channels), to the
     * tenant's current contact details. Each send shows in the bill's `deliveries`. `messages` lists each
     * channel: where it goes (`to`), or why it was skipped (`sending: false`, `note`).
     */
    public function resend(Request $request, Invoice $invoice, MessagePlan $plan): JsonResponse
    {
        Gate::authorize('manage', $invoice);
        $request->validate([
            /** Leave out for the tenant's usual channels. */
            'channels' => ['nullable', 'array', 'min:1'],
            'channels.*' => [Rule::in([NotificationChannel::Email->value, NotificationChannel::WhatsApp->value])],
        ]);

        InvoiceResendRequested::dispatch($invoice->id, $request->input('channels'));

        return response()->json([
            'message' => 'Sending the bill.',
            'messages' => $plan->forTenant($invoice->tenant()->firstOrFail(), $request->input('channels')),
        ], 202);
    }

    /**
     * Void a bill.
     *
     * For a bill that's wrong (e.g. a mistyped reading). It stays visible as void but stops counting
     * towards arrears; for a monthly bill the lease goes back one month so the next billing run bills that month
     * again. Only the lease's latest bill, and only before any payment is applied to it.
     */
    public function void(Request $request, Invoice $invoice): JsonResponse
    {
        Gate::authorize('manage', $invoice);
        $request->validate([
            /** Why it's void (kept on the bill and in the activity log). */
            'reason' => ['required', 'string', 'max:500'],
        ]);

        InvoiceVoidRequested::dispatch($invoice->id, (string) $request->input('reason'), $request->user()->id);

        return response()->json(['message' => 'Bill voided.'], 202);
    }
}
