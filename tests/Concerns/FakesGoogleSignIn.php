<?php

namespace Tests\Concerns;

use App\Services\Auth\GoogleIdTokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

/**
 * Google is never called in tests: a throwaway RSA key stands in for Google's signing key, its public
 * half is served as the JWKS, and googleIdToken() signs ID tokens with it.
 */
trait FakesGoogleSignIn
{
    private ?string $googlePrivateKey = null;

    protected function fakeGoogle(string $clientId = 'rentflow-web-client'): void
    {
        config(['services.google.client_ids' => [$clientId]]);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->googlePrivateKey);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        Http::fake([GoogleIdTokenVerifier::CERTS_URL => Http::response(
            ['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'test-key', 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])]]],
            200,
            ['Cache-Control' => 'public, max-age=3600'],
        )]);
    }

    /** A Google ID token for this email; override claims to test rejections. */
    protected function googleIdToken(string $email, array $claims = []): string
    {
        return JWT::encode([
            'iss' => 'https://accounts.google.com',
            'aud' => config('services.google.client_ids')[0],
            'sub' => '1234567890',
            'email' => $email,
            'email_verified' => true,
            'name' => 'Test Person',
            'iat' => time(),
            'exp' => time() + 3600,
            ...$claims,
        ], $this->googlePrivateKey, 'RS256', 'test-key');
    }
}
