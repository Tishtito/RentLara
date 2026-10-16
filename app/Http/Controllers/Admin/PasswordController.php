<?php

namespace App\Http\Controllers\Admin;

use App\Events\Commands\AdminPasswordResetRequested;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetPasswordRequest;
use App\Http\Resources\AdminUserResource;
use App\Services\Auth\AdminAuthService;
use App\Services\Auth\AdminPasswordService;
use App\Services\Auth\ApiTokenService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Passwords set from an emailed link: team invitations and "forgot password".
 *
 * Architecture exception (docs/ARCHITECTURE.md) for set-password: it logs the user in, so the session /
 * token must exist in the response.
 */
#[Group('Auth', weight: 0)]
class PasswordController extends Controller
{
    /**
     * Forgot password.
     *
     * Emails a "choose a new password" link (60 minutes) if the email has an admin login. The answer is
     * the same either way. Limited to 3 attempts per minute.
     *
     * @unauthenticated
     */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate([
            /** @example landlord@rentflow.test */
            'email' => ['required', 'email'],
        ]);

        AdminPasswordResetRequested::dispatch((string) $request->input('email'));

        return response()->json(['message' => 'If this email has a Rentflow login, a link is on its way.'], 202);
    }

    /**
     * Set password.
     *
     * From the link in a team invitation or password-reset email. Sets the password and logs in: a cookie
     * session in the browser, or an API token when `device_name` is sent (apps).
     *
     * @unauthenticated
     */
    public function set(SetPasswordRequest $request, AdminPasswordService $passwords, AdminAuthService $auth, ApiTokenService $tokens): JsonResponse
    {
        $user = $passwords->setPassword((string) $request->input('email'), (string) $request->input('token'), (string) $request->input('password'));

        if ($request->filled('device_name')) {
            [$user, $token] = $tokens->issue($user->email, (string) $request->input('password'), (string) $request->input('device_name'));

            return response()->json(['token' => $token, 'token_type' => 'Bearer', 'user' => new AdminUserResource($user)]);
        }

        $user = $auth->login($user->email, (string) $request->input('password'));
        $request->session()->regenerate();

        return response()->json(['user' => new AdminUserResource($user)]);
    }
}
