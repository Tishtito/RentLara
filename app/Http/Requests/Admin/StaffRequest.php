<?php

namespace App\Http\Requests\Admin;

use App\Data\StaffData;
use App\Support\CurrentAccount;
use App\Support\Phone;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** Invite a team member (POST) or change one (PUT team/{user}; email can't change). */
class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller checks "team.manage"
    }

    public function rules(): array
    {
        $inviting = $this->isMethod('post');

        return [
            /** @example Joseph Kariuki */
            'name' => ['required', 'string', 'max:120'],
            /**
             * Their login; the "set up your login" email goes here. Invite only.
             *
             * @example joseph@example.com
             */
            'email' => [$inviting ? 'required' : 'prohibited', 'email', 'max:255'],
            /** @example 0712 345 678 */
            'phone' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail) {
                if ($value && ! Phone::toE164((string) $value)) {
                    $fail('Enter a Kenyan mobile number, e.g. 0712 345 678.');
                }
            }],
            /** Role ids (GET /api/admin/roles): built-in or this account's own. At least one. */
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer'],
            /** Properties they look after (they see these even without "properties.view_all"). */
            'property_ids' => ['nullable', 'array'],
            'property_ids.*' => ['integer'],
        ];
    }

    public function toData(?int $userId = null): StaffData
    {
        return new StaffData(
            accountId: (int) app(CurrentAccount::class)->id(),
            name: (string) $this->input('name'),
            phone: $this->filled('phone') ? Phone::toE164((string) $this->input('phone')) : null,
            roleIds: array_map('intval', $this->input('role_ids', [])),
            propertyIds: array_map('intval', $this->input('property_ids', []) ?? []),
            email: $this->input('email'),
            userId: $userId,
            actorId: $this->user()?->id,
        );
    }
}
