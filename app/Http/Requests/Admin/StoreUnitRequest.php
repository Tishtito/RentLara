<?php

namespace App\Http\Requests\Admin;

use App\Data\UnitData;
use App\Enums\UnitStatus;
use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Add a unit to a property (POST) or edit one (PUT /units/{unit}). */
class StoreUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller checks the policy
    }

    public function rules(): array
    {
        return [
            /**
             * Unit / house number as written on the door and the bills.
             *
             * @example 7-7
             */
            'unit_number' => ['required', 'string', 'max:30'],
            /** @example 1BR */
            'type' => ['nullable', 'string', 'max:30'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'floor' => ['nullable', 'integer', 'min:0', 'max:200'],
            /**
             * Asking rent in KES; pre-fills move-in (the lease keeps its own rent).
             *
             * @example 15000
             */
            'default_rent' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'default_deposit' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999'],
            /** vacant, reserved or maintenance. "occupied" is set by move-in only. */
            'status' => ['sometimes', Rule::enum(UnitStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function toData(int $propertyId, ?Unit $unit = null): UnitData
    {
        return new UnitData(
            propertyId: $propertyId,
            unitNumber: trim((string) $this->input('unit_number')),
            defaultRent: number_format((float) $this->input('default_rent'), 2, '.', ''),
            defaultDeposit: number_format((float) $this->input('default_deposit', 0), 2, '.', ''),
            type: $this->input('type'),
            bedrooms: $this->filled('bedrooms') ? $this->integer('bedrooms') : null,
            floor: $this->filled('floor') ? $this->integer('floor') : null,
            status: $this->filled('status') ? UnitStatus::from($this->input('status')) : null,
            notes: $this->input('notes'),
            unitId: $unit?->id,
        );
    }
}
