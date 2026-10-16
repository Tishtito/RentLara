<?php

namespace App\Http\Resources;

use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Unit */
class UnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            /** @example 7-7 */
            'unit_number' => $this->unit_number,
            'type' => $this->type,
            'bedrooms' => $this->bedrooms,
            'floor' => $this->floor,
            'default_rent' => $this->default_rent,
            'default_deposit' => $this->default_deposit,
            'status' => $this->status,
            'notes' => $this->notes,
            /** The tenant living here now (null when vacant). `balance`: positive = owes, negative = credit. */
            'current_lease' => $this->whenLoaded('currentLease', fn () => $this->currentLease ? [
                'id' => $this->currentLease->id,
                'tenant_id' => $this->currentLease->tenant_id,
                'tenant_name' => trim($this->currentLease->tenant?->first_name.' '.$this->currentLease->tenant?->last_name),
                'tenant_status' => $this->currentLease->tenant?->status,
                'start_date' => $this->currentLease->start_date?->toDateString(),
                'monthly_rent' => $this->currentLease->monthly_rent,
                'next_billing_date' => $this->currentLease->next_billing_date?->toDateString(),
                'status' => $this->currentLease->status,
                'balance' => $this->currentLease->getAttribute('balance'),
            ] : null),
            'meters' => MeterResource::collection($this->whenLoaded('activeMeters')),
        ];
    }
}
