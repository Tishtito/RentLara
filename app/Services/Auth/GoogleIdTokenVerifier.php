<?php

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Verifies a Google Sign-In ID token (a JWT from Google Identity Services on the web, or the native
 * Google sign-in in the apps) and returns the verified email.
 *
 * Checks the signature against Google's published keys, the issuer, expiry, that the token was issued
 * to one of OUR client IDs (`aud`: a token minted for another app must not log anyone in here), and that
 * Google has verified the email address.
 */
class GoogleIdTokenVerifier
{
    public const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /**
     * @return array{email: string, name: string|null}
     *
     * @throws ValidationException on `credential` when the token isn't valid for this app
     */
    public function verify(string $idToken): array
    {
        $clientIds = config('services.google.client_ids', []);

        if ($clientIds === []) {
            throw ValidationException::withMessages(['credential' => 'Google sign-in is not set up.']);
        }

        try {
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($this->keys(), 'RS256'));
        } catch (Throwable) {
            throw ValidationException::withMessages(['credential' => 'Google sign-in failed. Please try again.']);
        }

        $valid = in_array($claims['iss'] ?? null, self::ISSUERS, true)
            && in_array($claims['aud'] ?? null, $clientIds, true)
            && filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && is_string($claims['email'] ?? null);

        if (! $valid) {
            throw ValidationException::withMessages(['credential' => 'Google sign-in failed. Please try again.']);
        }

        return ['email' => mb_strtolower($claims['email']), 'name' => $claims['name'] ?? null];
    }

    /** Google's signing keys (JWKS), cached for as long as Google says (Cache-Control max-age). */
    private function keys(): array
    {
        $cached = Cache::get('google-oauth-certs');
        if (is_array($cached)) {
            return $cached;
        }

        $response = Http::timeout(5)->get(self::CERTS_URL)->throw();
        preg_match('/max-age=(\d+)/', (string) $response->header('Cache-Control'), $m);

        $keys = $response->json();
        Cache::put('google-oauth-certs', $keys, now()->addSeconds(max(60, (int) ($m[1] ?? 3600))));

        return $keys;
    }
}
