<?php

namespace App\Data;

/** A property to create, or (with $propertyId) the new details of an existing one. */
final readonly class PropertyData
{
    public function __construct(
        public string $name,
        public ?string $code = null,
        public ?string $address = null,
        public ?string $town = null,
        public ?string $county = null,
        public ?int $caretakerId = null,
        public string $status = 'active',
        public ?string $notes = null,
        public ?int $propertyId = null,
    ) {}

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'town' => $this->town,
            'county' => $this->county,
            'caretaker_id' => $this->caretakerId,
            'status' => $this->status,
            'notes' => $this->notes,
        ];
    }
}
