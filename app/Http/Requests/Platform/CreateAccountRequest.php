<?php

namespace App\Http\Requests\Platform;

use App\Data\CreateAccountData;
use App\Support\Phone;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class CreateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // super admins only (route middleware)
    }

    public function rules(): array
    {
        $phone = function (string $attribute, mixed $value, Closure $fail) {
            if ($value && ! Phone::toE164((string) $value)) {
                $fail('Enter a Kenyan mobile number, e.g. 0712 345 678.');
            }
        };

        return [
            /** @example Ochieng Rentals */
            'business_name' => ['required', 'string', 'max:120'],
            /** The account's contact email (defaults to the owner's). */
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', $phone],
            /** @example Tom Ochieng */
            'owner_name' => ['required', 'string', 'max:120'],
            /**
             * The owner's login; the "set up your login" email goes here.
             *
             * @example tom@example.com
             */
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_phone' => ['nullable', 'string', 'max:20', $phone],
        ];
    }

    public function toData(): CreateAccountData
    {
        return new CreateAccountData(
            businessName: (string) $this->input('business_name'),
            email: $this->input('email'),
            phone: $this->filled('phone') ? Phone::toE164((string) $this->input('phone')) : null,
            ownerName: (string) $this->input('owner_name'),
            ownerEmail: (string) $this->input('owner_email'),
            ownerPhone: $this->filled('owner_phone') ? Phone::toE164((string) $this->input('owner_phone')) : null,
        );
    }
}
