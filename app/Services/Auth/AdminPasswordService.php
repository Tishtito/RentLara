<?php

namespace App\Services\Auth;

use App\Mail\AdminPasswordLinkMail;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Admin-portal passwords set from an emailed link ({ADMIN_URL}/set-password?token=…&email=…):
 * team invitations (3-day link, "staff_invites" broker) and "forgot password" (60-minute link).
 */
class AdminPasswordService
{
    public const INVITES = 'staff_invites';

    public function sendInvitation(User $user): void
    {
        if ($user->status !== 'invited') {
            return;
        }

        $token = Password::broker(self::INVITES)->createToken($user);
        $account = $user->account_id ? Account::query()->whereKey($user->account_id)->value('name') : null;

        Mail::to($user->email, $user->name)->send(new AdminPasswordLinkMail($user, $this->link($user, $token), true, $account));
    }

    /** Forgot password: always looks the same to the caller, whether or not the email has a login. */
    public function sendReset(string $email): void
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

        if (! $user || $user->status === 'suspended') {
            return;
        }

        $broker = $user->status === 'invited' ? self::INVITES : null;
        $token = Password::broker($broker)->createToken($user);

        Mail::to($user->email, $user->name)->queue(new AdminPasswordLinkMail($user, $this->link($user, $token), $user->status === 'invited'));
    }

    /**
     * Set the password from a link. Invited members become active; the link can't be used again.
     *
     * @throws ValidationException invalid / expired link, or a suspended login
     */
    public function setPassword(string $email, string $token, string $password): User
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();
        $broker = Password::broker($user?->status === 'invited' ? self::INVITES : null);

        if (! $user || ! $broker->tokenExists($user, $token)) {
            throw ValidationException::withMessages(['token' => 'This link has expired or was already used. Ask for a new one.']);
        }

        if ($user->status === 'suspended') {
            throw ValidationException::withMessages(['token' => 'Your login is disabled. Contact the account owner.']);
        }

        $user->forceFill([
            'password' => $password,
            'status' => 'active',
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();
        $broker->deleteToken($user);

        return $user;
    }

    private function link(User $user, string $token): string
    {
        return rtrim((string) config('app.admin_url'), '/').'/set-password?'.http_build_query(['token' => $token, 'email' => $user->email]);
    }
}
