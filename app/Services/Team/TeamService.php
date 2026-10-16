<?php

namespace App\Services\Team;

use App\Data\StaffData;
use App\Enums\SystemRole;
use App\Enums\UserRole;
use App\Events\StaffInvited;
use App\Models\Property;
use App\Models\User;
use App\Services\Authorization\RoleService;
use App\Support\CurrentAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * The people who work in a landlord account (users with role "staff"): invite, change roles and the
 * properties they look after, suspend, remove. Guards: every account keeps an active Owner, and nobody
 * changes their own roles, suspends or removes themselves.
 */
class TeamService
{
    public function __construct(
        private RoleService $roles,
        private CurrentAccount $context,
    ) {}

    /** Everyone in the account (active, invited, suspended), with roles and the properties they look after. */
    public function members(int $accountId): Collection
    {
        return $this->context->actingAs($accountId, function () use ($accountId) {
            $users = User::query()->where('account_id', $accountId)->where('role', UserRole::Staff)
                ->with(['roles:id,name', 'caretakerProperties:id,name,caretaker_id'])
                ->orderBy('name')->get();

            return $users;
        });
    }

    public function find(int $accountId, int $userId): User
    {
        return User::query()->where('account_id', $accountId)->where('role', UserRole::Staff)->findOrFail($userId);
    }

    /** @throws ValidationException email already used, or a role/property that isn't this account's */
    public function invite(StaffData $data): User
    {
        $email = mb_strtolower(trim((string) $data->email));

        if (User::withTrashed()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'Someone already uses this email to log in.']);
        }

        $user = DB::transaction(function () use ($data, $email) {
            $user = User::query()->create([
                'account_id' => $data->accountId,
                'role' => UserRole::Staff,
                'name' => trim($data->name),
                'email' => $email,
                'phone' => $data->phone,
                'password' => Hash::make(Str::random(48)), // unusable until they set one from the email
                'status' => 'invited',
            ]);

            $this->context->actingAs($data->accountId, function () use ($user, $data) {
                $user->syncRoles($this->rolesFor($data));
                $this->assignProperties($user, $data->propertyIds);
            });

            return $user;
        });

        StaffInvited::dispatch($user);

        return $user;
    }

    /** @throws ValidationException own roles, last Owner, or a role/property that isn't this account's */
    public function update(StaffData $data): User
    {
        $user = $this->find($data->accountId, (int) $data->userId);

        return DB::transaction(fn () => $this->context->actingAs($data->accountId, function () use ($user, $data) {
            $roles = $this->rolesFor($data);
            $current = $user->getRoleNames()->sort()->values()->all();
            $next = collect($roles)->pluck('name')->sort()->values()->all();

            if ($current !== $next) {
                if ($user->id === $data->actorId) {
                    throw ValidationException::withMessages(['role_ids' => 'You can\'t change your own roles. Ask another Owner.']);
                }
                if (in_array(SystemRole::Owner->value, $current, true) && ! in_array(SystemRole::Owner->value, $next, true)) {
                    $this->ensureAnotherOwner($data->accountId, $user);
                }
            }

            $user->forceFill(['name' => trim($data->name), 'phone' => $data->phone])->save();
            $user->syncRoles($roles);
            $this->assignProperties($user, $data->propertyIds);

            return $user;
        }));
    }

    /** Suspend (can't log in) or reactivate. */
    public function setStatus(int $accountId, int $userId, string $status, ?int $actorId): User
    {
        $user = $this->find($accountId, $userId);

        if ($user->id === $actorId) {
            throw ValidationException::withMessages(['status' => 'You can\'t suspend yourself.']);
        }

        if ($status === 'suspended') {
            $this->context->actingAs($accountId, fn () => $user->hasRole(SystemRole::Owner->value) && $this->ensureAnotherOwner($accountId, $user));
            $user->tokens()->delete(); // apps lose access right away
        }

        $user->forceFill(['status' => $status === 'active' && $user->status === 'invited' ? 'invited' : $status])->save();

        return $user;
    }

    /** Remove from the team (soft delete): roles, properties they looked after and tokens go. */
    public function remove(int $accountId, int $userId, ?int $actorId): void
    {
        $user = $this->find($accountId, $userId);

        if ($user->id === $actorId) {
            throw ValidationException::withMessages(['user' => 'You can\'t remove yourself.']);
        }

        DB::transaction(fn () => $this->context->actingAs($accountId, function () use ($accountId, $user) {
            if ($user->hasRole(SystemRole::Owner->value)) {
                $this->ensureAnotherOwner($accountId, $user);
            }
            $user->syncRoles([]);
            Property::query()->where('caretaker_id', $user->id)->update(['caretaker_id' => null]);
            $user->tokens()->delete();
            $user->delete();
        }));
    }

    /** Send the "set your password" email again (invited members only). */
    public function resendInvitation(int $accountId, int $userId): void
    {
        $user = $this->find($accountId, $userId);

        if ($user->status !== 'invited') {
            throw ValidationException::withMessages(['user' => 'This person has already set their password.']);
        }

        StaffInvited::dispatch($user);
    }

    /** @return list<Role> */
    private function rolesFor(StaffData $data): array
    {
        if ($data->roleIds === []) {
            throw ValidationException::withMessages(['role_ids' => 'Choose at least one role.']);
        }

        return array_map(function (int $id) use ($data) {
            return $this->roles->findForAccount($id, $data->accountId)
                ?? throw ValidationException::withMessages(['role_ids' => 'Choose roles from this account.']);
        }, array_values(array_unique($data->roleIds)));
    }

    /** They look after exactly these properties (one caretaker per property). */
    private function assignProperties(User $user, array $propertyIds): void
    {
        Property::query()->where('caretaker_id', $user->id)->whereNotIn('id', $propertyIds)->update(['caretaker_id' => null]);

        if ($propertyIds !== []) {
            $found = Property::query()->whereKey($propertyIds)->count();
            if ($found !== count(array_unique($propertyIds))) {
                throw ValidationException::withMessages(['property_ids' => 'Choose properties from this account.']);
            }
            Property::query()->whereKey($propertyIds)->update(['caretaker_id' => $user->id]);
        }
    }

    /** @throws ValidationException when $user is the account's last active Owner */
    private function ensureAnotherOwner(int $accountId, User $user): bool
    {
        $others = User::query()->where('account_id', $accountId)->where('status', 'active')->whereKeyNot($user->id)
            ->role(SystemRole::Owner->value)->exists();

        if (! $others) {
            throw ValidationException::withMessages(['role_ids' => 'Every account needs at least one active Owner. Make someone else Owner first.']);
        }

        return true;
    }
}
