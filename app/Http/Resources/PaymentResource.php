<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lease_id' => $this->lease_id,
            'tenant_id' => $this->tenant_id,
            'invoice_id' => $this->invoice_id,
            'amount' => $this->amount,
            /**
             * The lease balance right after this payment (positive = still owed, negative = credit);
             * null on payments from before receipts.
             */
            'balance_after' => $this->balance_after,
            'method' => $this->method,
            'reference' => $this->reference,
            /**
             * Null until the payment is confirmed.
             *
             * @example RCT-2026-000001
             */
            'receipt_number' => $this->receipt_number,
            'payer_phone' => $this->payer_phone,
            'paid_at' => $this->paid_at,
            'status' => $this->status,
            'notes' => $this->notes,
            'tenant' => $this->whenLoaded('tenant', fn () => [
                'id' => $this->tenant->id,
                'full_name' => trim("{$this->tenant->first_name} {$this->tenant->last_name}"),
            ]),
            /** @example 7-7 */
            'unit_number' => $this->whenLoaded('lease', fn () => $this->lease->unit?->unit_number),
            /** @example INV-2026-000001 */
            'invoice_number' => $this->whenLoaded('invoice', fn () => $this->invoice?->invoice_number),
        ];
    }
}
