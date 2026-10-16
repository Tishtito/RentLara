<?php

namespace App\Services\Auth;

use App\Models\Account;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Admin-portal logins (users table). Used by both the bearer-token API (ApiTokenService)
 * and the React admin portal's cookie session.
 */
class AdminAuthService
{
    public function __construct(private GoogleIdTokenVerifier $google) {}

    /**
     * Check an email + password and that the login and its landlord account are active.
     *
     * @throws ValidationException wrong email or password (same message for both)
     * @throws AuthorizationException login or landlord account suspended
     */
    public function verify(string $email, string $password): User
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        return $this->ensureCanLogIn($user);
    }

    /**
     * "Continue with Google": the login whose email Google verified. Only existing logins; Google
     * never creates an account.
     *
     * @throws ValidationException invalid Google token, or no login with that email
     * @throws AuthorizationException login or landlord account suspended
     */
    public function verifyGoogle(string $idToken): User
    {
        $google = $this->google->verify($idToken);
        $user = User::query()->where('email', $google['email'])->first();

        if (! $user) {
            throw ValidationException::withMessages(['credential' => 'No Rentflow account uses this Google email. Sign up first.']);
        }

        return $this->ensureCanLogIn($user);
    }

    /** Cookie-session login with Google (admin SPA). The caller regenerates the session id. */
    public function loginWithGoogle(string $idToken, bool $remember = false): User
    {
        $user = $this->verifyGoogle($idToken);

        Auth::guard('web')->login($user, $remember);
        $this->recordLogin($user);

        return $user;
    }

    /** @throws AuthorizationException login or landlord account suspended */
    private function ensureCanLogIn(User $user): User
    {
        if ($user->status === 'invited') {
            throw new AuthorizationException('Set your password from the invitation email first.');
        }

        if (! $user->isActive()) {
            throw new AuthorizationException('Your login is disabled.');
        }

        if ($user->account_id && ! Account::query()->whereKey($user->account_id)->where('status', 'active')->exists()) {
            throw new AuthorizationException('This account is suspended.');
        }

        return $user;
    }

    /** Cookie-session login for the admin SPA. The caller regenerates the session id. */
    public function login(string $email, string $password, bool $remember = false): User
    {
        $user = $this->verify($email, $password);

        Auth::guard('web')->login($user, $remember);
        $this->recordLogin($user);

        return $user;
    }

    /** Logs out the admin guard only; a tenant session in the same browser stays logged in. */
    public function logout(): void
    {
        Auth::guard('web')->logout();
    }

    public function recordLogin(User $user): void
    {
        $user->forceFill(['last_login_at' => now()])->save();
    }
}
