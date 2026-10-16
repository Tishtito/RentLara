<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use App\Services\Ledger\LeaseLedgerService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            /** move_in (deposit + rent up to the first billing day), monthly (billing day) or adjustment. */
            'type' => $this->type,
            'lease_id' => $this->lease_id,
            'tenant_id' => $this->tenant_id,
            'unit_id' => $this->unit_id,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'current_charges' => $this->current_charges,
            'balance_brought_forward' => $this->balance_brought_forward,
            'total_due' => $this->total_due,
            'amount_paid' => $this->amount_paid,
            /** What's still owed on this bill (0 when void). */
            'balance_remaining' => match (true) {
                $this->isVoid() => '0.00',
                // Lists: total − paid (same answer, no ledger query per row).
                $request->route()?->getActionMethod() === 'index' => Money::fromCents(max(0, Money::toCents($this->total_due) - Money::toCents($this->amount_paid))),
                default => app(LeaseLedgerService::class)->balanceRemaining($this->resource),
            },
            'status' => $this->status,
            'voided_at' => $this->voided_at,
            'void_reason' => $this->void_reason,
            'voided_by' => $this->whenLoaded('voidedBy', fn () => $this->voidedBy?->name),
            'sent_at' => $this->sent_at,
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'tenant' => $this->whenLoaded('tenant', fn () => [
                'id' => $this->tenant->id,
                'full_name' => trim("{$this->tenant->first_name} {$this->tenant->last_name}"),
                'phone' => $this->tenant->phone,
                'email' => $this->tenant->email,
            ]),
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $this->unit->id,
                'unit_number' => $this->unit->unit_number,
                'property_name' => $this->unit->property?->name,
            ]),
            /** Every time the bill was sent (newest first): only on GET invoices/{id}. */
            'deliveries' => $this->whenLoaded('notificationDeliveries', fn () => $this->notificationDeliveries->map(fn ($d) => [
                'id' => $d->id,
                /** bill_issued (automatic) or bill_resent (sent again by hand) */
                'type' => $d->type,
                'channel' => $d->channel,
                'recipient' => $d->recipient,
                'status' => $d->status,
                'error' => $d->error,
                'sent_at' => $d->sent_at,
                'delivered_at' => $d->delivered_at,
                'created_at' => $d->created_at,
            ])->values()),
        ];
    }
}
