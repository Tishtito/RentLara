<?php

namespace App\Services\Platform;

use App\Data\CreateAccountData;
use App\Data\StaffData;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\PermissionCatalog;
use App\Services\Team\TeamService;
use App\Support\AccountSlug;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Platform admins open and run landlord accounts: create one (its owner is invited by email) and
 * suspend / close / reactivate it.
 */
class AccountProvisioningService
{
    public const STATUSES = ['active', 'suspended', 'closed'];

    public function __construct(
        private TeamService $team,
        private PermissionCatalog $catalog,
    ) {}

    /** @throws ValidationException the owner's email already has a login */
    public function create(CreateAccountData $data): Account
    {
        $ownerEmail = mb_strtolower(trim($data->ownerEmail));

        if (User::withTrashed()->where('email', $ownerEmail)->exists()) {
            throw ValidationException::withMessages(['owner_email' => 'Someone already uses this email to log in.']);
        }

        return DB::transaction(function () use ($data, $ownerEmail) {
            $account = Account::query()->create([
                'name' => trim($data->businessName),
                'slug' => AccountSlug::unique($data->businessName),
                'email' => $data->email ? mb_strtolower(trim($data->email)) : $ownerEmail,
                'phone' => $data->phone ?? $data->ownerPhone,
                'status' => 'active',
            ]);

            // Same path as inviting staff: status "invited", Owner role, "set up your login" email after commit.
            $this->team->invite(new StaffData(
                accountId: $account->id,
                name: $data->ownerName,
                phone: $data->ownerPhone,
                roleIds: [$this->catalog->template(SystemRole::Owner)->id],
                email: $ownerEmail,
            ));

            return $account;
        });
    }

    /**
     * Suspended / closed accounts can't log in (staff or tenants) and aren't billed; their app tokens are
     * revoked now. Reactivating restores access; nothing is deleted.
     */
    public function setStatus(int $accountId, string $status): Account
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Choose active, suspended or closed.']);
        }

        $account = Account::query()->findOrFail($accountId);

        DB::transaction(function () use ($account, $status) {
            $account->forceFill(['status' => $status])->save();

            if ($status !== 'active') {
                $users = User::query()->where('account_id', $account->id)->pluck('id');
                $tenants = Tenant::withoutGlobalScopes()->where('account_id', $account->id)->pluck('id');

                PersonalAccessToken::query()
                    ->where(fn ($q) => $q->where('tokenable_type', 'user')->whereIn('tokenable_id', $users))
                    ->orWhere(fn ($q) => $q->where('tokenable_type', 'tenant')->whereIn('tokenable_id', $tenants))
                    ->delete();
            }
        });

        return $account;
    }
}
