<?php

namespace App\Http\Requests\Admin;

use App\Data\RegisterLandlordData;
use App\Support\Phone;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterLandlordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    public function rules(): array
    {
        return [
            /** @example Kim Wanjiru */
            'name' => ['required', 'string', 'max:120'],
            /**
             * The name tenants see on bills. Defaults to the full name.
             *
             * @example Kims Properties
             */
            'business_name' => ['nullable', 'string', 'max:120'],
            /**
             * Kenyan mobile number, any common format.
             *
             * @example 0712 345 678
             */
            'phone' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail) {
                if (! Phone::toE164((string) $value)) {
                    $fail('Enter a Kenyan mobile number, e.g. 0712 345 678.');
                }
            }],
            /** @example kim@example.com */
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }

    public function messages(): array
    {
        return ['email.unique' => 'An account with this email already exists. Log in instead.'];
    }

    public function toData(): RegisterLandlordData
    {
        $name = trim((string) $this->input('name'));

        return new RegisterLandlordData(
            name: $name,
            businessName: trim((string) $this->input('business_name')) ?: $name,
            email: (string) $this->input('email'),
            phone: Phone::toE164((string) $this->input('phone')),
            password: (string) $this->input('password'),
        );
    }
}
