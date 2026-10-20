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
            'method' => $this->method,
            'reference' => $this->reference,
            'paid_at' => $this->paid_at,
            /** @example INV-2026-000001 */
            'invoice_number' => $this->whenLoaded('invoice', fn () => $this->invoice?->invoice_number),
        ];
    }
}
