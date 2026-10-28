<?php

namespace App\Http\Requests\Admin;

use App\Data\PropertyData;
use App\Enums\UserRole;
use App\Models\Property;
use App\Models\User;
use App\Support\CurrentAccount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** Create (POST) or edit (PUT /properties/{property}) a property. */
class StorePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller checks the policy
    }

    /** Add a property (POST) or edit one (PUT: fields you leave out keep their value). */
    public function rules(): array
    {
        return [
            /** @example Kikuyu Court */
            'name' => ['required', 'string', 'max:120'],
            /**
             * Short code used in unit labels and M-Pesa account numbers.
             *
             * @example KC
             */
            'code' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            /** @example Kikuyu */
            'town' => ['nullable', 'string', 'max:120'],
            /** @example Kiambu */
            'county' => ['nullable', 'string', 'max:120'],
            /** A staff member of this account who looks after the property (they see it even without "properties.view_all"). */
            'caretaker_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                $ok = User::query()
                    ->whereKey((int) $value)
                    ->where('account_id', app(CurrentAccount::class)->id())
                    ->where('role', UserRole::Staff)
                    ->exists();
                if (! $ok) {
                    $fail('Choose someone from your team.');
                }
            }],
            'status' => ['sometimes', 'in:active,inactive'],
            'notes' => ['nullable', 'string', 'max:2000'],
            /**
             * Day of the month (1–28) this property's units bill on; null = the account's billing day. Units can
             * have their own. Changing it moves running leases to the first new billing day on or after their
             * next bill, and that bill also charges the days in between.
             *
             * @example 20
             */
            'billing_day' => ['nullable', 'integer', 'between:1,28'],
        ];
    }

    public function messages(): array
    {
        return ['billing_day.between' => 'Choose a day from 1 to 28 (it must exist in every month).'];
    }

    /**
     * On edit (`$property` given), fields the request leaves out keep their current value; send a field as
     * null / empty to clear it.
     */
    public function toData(?Property $property = null): PropertyData
    {
        $keep = fn (string $key, mixed $current) => $property && ! $this->has($key) ? $current : $this->input($key);
        $caretaker = $keep('caretaker_id', $property?->caretaker_id);

        return new PropertyData(
            name: trim((string) $this->input('name')),
            code: $keep('code', $property?->code),
            address: $keep('address', $property?->address),
            town: $keep('town', $property?->town),
            county: $keep('county', $property?->county),
            caretakerId: $caretaker === null || $caretaker === '' ? null : (int) $caretaker,
            status: $keep('status', $property?->status) ?? 'active',
            notes: $keep('notes', $property?->notes),
            propertyId: $property?->id,
            billingDay: ($day = $keep('billing_day', $property?->billing_day)) === null || $day === '' ? null : (int) $day,
        );
    }
}
