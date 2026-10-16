<?php

namespace App\Services\Platform;

use App\Enums\UserRole;
use App\Events\StaffInvited;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Platform admins (super admins): invite, suspend, remove. There is always at least one active one, and
 * nobody suspends or removes themselves.
 */
class PlatformAdminService
{
    public function list(): Collection
    {
        return User::query()->where('role', UserRole::SuperAdmin)->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'status', 'last_login_at', 'created_at']);
    }

    /** @throws ValidationException email already used */
    public function invite(string $name, string $email): User
    {
        $email = mb_strtolower(trim($email));

        if (User::withTrashed()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'Someone already uses this email to log in.']);
        }

        $user = User::query()->create([
            'account_id' => null,
            'role' => UserRole::SuperAdmin,
            'name' => trim($name),
            'email' => $email,
            'password' => Hash::make(Str::random(48)), // unusable until they set one from the email
            'status' => 'invited',
        ]);

        StaffInvited::dispatch($user); // same "set up your login" email and /set-password page as team members

        return $user;
    }

    /** @param  'active'|'suspended'|'removed'|'reinvite'  $action */
    public function change(int $userId, string $action, ?int $actorId): void
    {
        $user = User::query()->where('role', UserRole::SuperAdmin)->findOrFail($userId);

        if ($action === 'reinvite') {
            if ($user->status !== 'invited') {
                throw ValidationException::withMessages(['user' => 'This admin has already set their password.']);
            }
            StaffInvited::dispatch($user);

            return;
        }

        if ($user->id === $actorId) {
            throw ValidationException::withMessages(['user' => $action === 'removed' ? 'You can\'t remove yourself.' : 'You can\'t suspend yourself.']);
        }

        if ($action !== 'active') {
            $othersActive = User::query()->where('role', UserRole::SuperAdmin)->where('status', 'active')->whereKeyNot($user->id)->exists();
            if (! $othersActive) {
                throw ValidationException::withMessages(['user' => 'There must always be at least one active platform admin.']);
            }
        }

        DB::transaction(function () use ($user, $action) {
            if ($action === 'removed') {
                $user->tokens()->delete();
                $user->delete();

                return;
            }

            if ($action === 'suspended') {
                $user->tokens()->delete();
            }

            $user->forceFill(['status' => $action === 'active' && $user->status === 'invited' ? 'invited' : $action])->save();
        });
    }
}
