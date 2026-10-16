<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One version of a price. @mixin \App\Models\ChargeRate */
class ChargeRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            /** null = every unit of the property; otherwise an override for this unit. */
            'unit_id' => $this->unit_id,
            'unit_number' => $this->whenLoaded('unit', fn () => $this->unit?->unit_number),
            'charge_code' => $this->charge_code,
            'label' => $this->label,
            'billing_type' => $this->billing_type,
            'rate' => $this->rate,
            'unit_of_measure' => $this->unit_of_measure,
            'effective_from' => $this->effective_from?->toDateString(),
            /** null = the current price. */
            'effective_to' => $this->effective_to?->toDateString(),
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
        ];
    }
}
