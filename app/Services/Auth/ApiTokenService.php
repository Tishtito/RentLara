<?php

namespace App\Services\Auth;

use App\Events\ApiTokenIssued;
use App\Events\ApiTokenRevoked;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum personal access tokens for the admin API (API clients, the docs' "Test request").
 * The React admin portal uses cookie sessions instead (AdminAuthService).
 */
class ApiTokenService
{
    public function __construct(private AdminAuthService $auth) {}

    /**
     * @return array{0: User, 1: string} the user and the plain-text token (shown once)
     *
     * @throws ValidationException wrong email or password (same message for both)
     * @throws AuthorizationException login or landlord account suspended
     */
    public function issue(string $email, string $password, string $deviceName): array
    {
        return $this->issueFor($this->auth->verify($email, $password), $deviceName);
    }

    /**
     * Token for "Continue with Google" in the admin app.
     *
     * @return array{0: User, 1: string}
     */
    public function issueForGoogle(string $idToken, string $deviceName): array
    {
        return $this->issueFor($this->auth->verifyGoogle($idToken), $deviceName);
    }

    /** @return array{0: User, 1: string} */
    private function issueFor(User $user, string $deviceName): array
    {
        $token = $user->createToken($deviceName, ['admin']);
        $this->auth->recordLogin($user);

        ApiTokenIssued::dispatch($user, $token->accessToken);

        return [$user, $token->plainTextToken];
    }

    /** Revoke the token the request was made with. */
    public function revoke(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
            ApiTokenRevoked::dispatch($user, $token);
        }
    }
}
