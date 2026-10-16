<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Like `exists`, but through Eloquent so the account scope applies: an ID that
 * belongs to another landlord fails exactly like an ID that doesn't exist.
 * (The built-in `exists` rule queries the table directly and ignores the scope.)
 */
class InCurrentAccount implements ValidationRule
{
    /** @param  class-string<Model>  $model */
    public function __construct(private string $model) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value) || ! $this->model::query()->whereKey((int) $value)->exists()) {
            $fail('The selected :attribute is invalid.');
        }
    }
}
