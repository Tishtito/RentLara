<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\GoogleLoginRequest;
use App\Http\Resources\AdminUserResource;
use App\Services\Auth\AdminAuthService;
use App\Services\Auth\ApiTokenService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * "Continue with Google" for the admin portal. Logs in existing landlords, caretakers and admins
 * whose email Google has verified; it never creates an account (use sign-up first).
 *
 * Architecture exception (docs/ARCHITECTURE.md): the session / issued token must exist in the
 * response, so these actions call the auth services directly instead of dispatching an intent event.
 */
#[Group('Auth', weight: 0)]
class GoogleLoginController extends Controller
{
    /**
     * Log in with Google (admin portal, browser).
     *
     * Send the Google ID token as `credential`. Starts a cookie session like `POST /api/admin/auth/login`
     * (call `GET /sanctum/csrf-cookie` first). Limited to 6 attempts per minute.
     *
     * @unauthenticated
     */
    public function store(GoogleLoginRequest $request, AdminAuthService $auth): JsonResponse
    {
        $user = $auth->loginWithGoogle($request->string('credential'), $request->boolean('remember'));

        $request->session()->regenerate();

        return response()->json(['user' => new AdminUserResource($user)]);
    }

    /**
     * Issue an API token with Google (admin app).
     *
     * Like `POST /api/admin/auth/token`, but with a Google ID token instead of a password.
     *
     * @unauthenticated
     */
    public function token(GoogleLoginRequest $request, ApiTokenService $tokens): JsonResponse
    {
        [$user, $token] = $tokens->issueForGoogle($request->string('credential'), $request->input('device_name') ?: 'admin-app');

        return response()->json([
            /** @example 1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx */
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new AdminUserResource($user),
        ]);
    }
}
