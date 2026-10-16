<?php

namespace App\Http\Requests\Admin;

use App\Support\CurrentAccount;
use App\Support\Phone;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller checks the policy
    }

    public function rules(): array
    {
        $account = app(CurrentAccount::class)->id();
        $tenantId = $this->route('tenant')?->id;
        $unique = fn (string $column) => Rule::unique('tenants', $column)->where('account_id', $account)->whereNull('deleted_at')->ignore($tenantId);
        $phone = function (string $attribute, mixed $value, Closure $fail) {
            if ($value !== null && $value !== '' && ! Phone::toE164((string) $value)) {
                $fail('Enter a Kenyan mobile number, e.g. 0712 345 678.');
            }
        };

        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:20', $phone],
            /** Bills on WhatsApp go here; empty = same as phone. */
            'whatsapp_phone' => ['nullable', 'string', 'max:20', $phone],
            'email' => ['nullable', 'email', 'max:255', $unique('email')],
            'national_id' => ['nullable', 'string', 'max:20', $unique('national_id')],
            'next_of_kin_name' => ['nullable', 'string', 'max:120'],
            'next_of_kin_phone' => ['nullable', 'string', 'max:20', $phone],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'You already have a tenant with this email.',
            'national_id.unique' => 'You already have a tenant with this national ID.',
        ];
    }

    /** @return array<string, mixed> */
    public function attributesToSave(): array
    {
        $phone = fn (string $key) => $this->filled($key) ? Phone::toE164((string) $this->input($key)) : null;

        return [
            'first_name' => trim((string) $this->input('first_name')),
            'last_name' => trim((string) $this->input('last_name')),
            'phone' => $phone('phone'),
            'whatsapp_phone' => $phone('whatsapp_phone'),
            'email' => $this->input('email') ?: null,
            'national_id' => trim((string) $this->input('national_id')) ?: null,
            'next_of_kin_name' => $this->input('next_of_kin_name'),
            'next_of_kin_phone' => $phone('next_of_kin_phone'),
        ];
    }
}
