<?php

namespace App\Http\Requests\Platform;

use App\Data\AccountProfileData;
use App\Support\Phone;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // super admins only (route middleware)
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail) {
                if ($value && ! Phone::toE164((string) $value)) {
                    $fail('Enter a Kenyan mobile number, e.g. 0712 345 678.');
                }
            }],
            /** @example Africa/Nairobi */
            'timezone' => ['required', 'timezone:all'],
            /** Day of the month every lease is billed (1–28). */
            'billing_day' => ['required', 'integer', 'min:1', 'max:28'],
            /** Due date = bill date + N days. */
            'payment_due_days' => ['required', 'integer', 'min:0', 'max:60'],
            /** Start of bill numbers, e.g. INV. */
            'invoice_prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
        ];
    }

    public function toData(int $accountId): AccountProfileData
    {
        return new AccountProfileData(
            accountId: $accountId,
            name: (string) $this->input('name'),
            email: $this->input('email'),
            phone: $this->filled('phone') ? Phone::toE164((string) $this->input('phone')) : null,
            timezone: (string) $this->input('timezone'),
            billingDay: (int) $this->input('billing_day'),
            paymentDueDays: (int) $this->input('payment_due_days'),
            invoicePrefix: strtoupper((string) $this->input('invoice_prefix')),
        );
    }
}
