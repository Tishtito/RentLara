<?php

namespace App\Http\Resources;

use App\Models\Meter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Meter */
class MeterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'unit_id' => $this->unit_id,
            /** @example electricity */
            'charge_code' => $this->charge_code,
            'meter_number' => $this->meter_number,
            'initial_reading' => $this->initial_reading,
            'is_active' => $this->is_active,
        ];
    }
}
