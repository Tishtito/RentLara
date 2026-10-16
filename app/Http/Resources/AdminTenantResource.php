<?php

namespace App\Http\Resources;

use App\Models\Lease;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A tenant as the landlord sees them (lists and the tenant page). @mixin \App\Models\Tenant */
class AdminTenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => trim("{$this->first_name} {$this->last_name}"),
            'email' => $this->email,
            'phone' => $this->phone,
            'whatsapp_phone' => $this->whatsapp_phone,
            'national_id' => $this->national_id,
            'next_of_kin_name' => $this->next_of_kin_name,
            'next_of_kin_phone' => $this->next_of_kin_phone,
            /** invited = hasn't set a portal password yet; active = can log in. */
            'status' => $this->status,
            'last_login_at' => $this->last_login_at,
            'current_lease' => $this->whenLoaded('activeLease', fn () => $this->activeLease ? self::lease($this->activeLease) : null),
            'leases' => $this->whenLoaded('leases', fn () => $this->leases->map(fn (Lease $l) => self::lease($l))->all()),
            'invoices' => InvoiceResource::collection($this->whenLoaded('invoices')),
            'payments' => PaymentResource::collection($this->whenLoaded('recentPayments')),
        ];
    }

    /** @return array<string, mixed> */
    private static function lease(Lease $lease): array
    {
        return [
            'id' => $lease->id,
            'unit_id' => $lease->unit_id,
            'unit_number' => $lease->unit?->unit_number,
            'property_id' => $lease->unit?->property_id,
            'property_name' => $lease->unit?->property?->name,
            'start_date' => $lease->start_date?->toDateString(),
            'end_date' => $lease->end_date?->toDateString(),
            'monthly_rent' => $lease->monthly_rent,
            'deposit_amount' => $lease->deposit_amount,
            'next_billing_date' => $lease->next_billing_date?->toDateString(),
            'status' => $lease->status,
            /** Running balance: positive = owes, negative = credit. */
            'balance' => $lease->getAttribute('balance'),
        ];
    }
}
