<?php

namespace App\Data;

/** A team member to invite, or (with $userId) the new details of an existing one. */
final readonly class StaffData
{
    /**
     * @param  list<int>  $roleIds  roles in the account (templates or its own)
     * @param  list<int>  $propertyIds  properties they look after (properties.caretaker_id)
     */
    public function __construct(
        public int $accountId,
        public string $name,
        public ?string $phone,
        public array $roleIds,
        public array $propertyIds = [],
        public ?string $email = null, // invite only; email is the login and isn't changed later
        public ?int $userId = null,
        public ?int $actorId = null,
    ) {}
}
