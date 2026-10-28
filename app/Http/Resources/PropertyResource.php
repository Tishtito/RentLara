<?php

namespace App\Http\Resources;

use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Property */
class PropertyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'town' => $this->town,
            'county' => $this->county,
            'status' => $this->status,
            'notes' => $this->notes,
            /** This property's own billing day (1–28), or null = the account's. */
            'billing_day' => $this->billing_day,
            /** The day its units bill on unless they have their own: billing_day ?? the account's. */
            'effective_billing_day' => $this->whenHas('effective_billing_day'),
            'caretaker' => $this->whenLoaded('caretaker', fn () => $this->caretaker ? [
                'id' => $this->caretaker->id,
                'name' => $this->caretaker->name,
                'phone' => $this->caretaker->phone,
            ] : null),
            'units_count' => $this->whenHas('units_count'),
            'occupied_count' => $this->whenHas('occupied_count'),
            'vacant_count' => $this->whenHas('vacant_count'),
            /** Sum of what tenants in this property owe (positive lease balances). */
            'arrears_total' => $this->whenHas('arrears_total'),
        ];
    }
}
