<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment as the tenant sees it in the portal: no landlord notes, who recorded it or the payer's phone.
 *
 * @mixin Payment
 */
class TenantPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lease_id' => $this->lease_id,
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
            'paid_at' => $this->paid_at,
            /** @example INV-2026-000001 */
            'invoice_number' => $this->whenLoaded('invoice', fn () => $this->invoice?->invoice_number),
        ];
    }
}
