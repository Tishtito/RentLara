<?php

namespace App\Services\Accounts;

use App\Data\RegisterLandlordData;
use App\Enums\SystemRole;
use App\Enums\UserRole;
use App\Events\LandlordRegistered;
use App\Models\Account;
use App\Models\User;
use App\Services\Authorization\PermissionCatalog;
use App\Support\AccountSlug;
use App\Support\CurrentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Self-service sign-up from the admin portal: a new landlord account and its landlord login. */
class AccountRegistrationService
{
    public function __construct(
        private CurrentAccount $context,
        private PermissionCatalog $catalog,
    ) {}

    /** @throws ValidationException the email is already used by another login */
    public function register(RegisterLandlordData $data): User
    {
        $email = mb_strtolower(trim($data->email));

        // Also checked by the form request; repeated here because the service is the source of truth.
        if (User::withTrashed()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'An account with this email already exists. Log in instead.']);
        }

        [$account, $user] = DB::transaction(function () use ($data, $email) {
            $account = Account::create([
                'name' => $data->businessName,
                'slug' => AccountSlug::unique($data->businessName),
                'email' => $email,
                'phone' => $data->phone,
            ]);

            $user = User::create([
                'account_id' => $account->id,
                'role' => UserRole::Staff,
                'name' => $data->name,
                'email' => $email,
                'phone' => $data->phone,
                'password' => $data->password,
                'status' => 'active',
            ]);

            // The person who signs up owns the account (every permission, incl. roles and team).
            $this->context->actingAs($account, fn () => $user->assignRole($this->catalog->template(SystemRole::Owner)));

            return [$account, $user];
        });

        LandlordRegistered::dispatch($account, $user);

        return $user;
    }
}
