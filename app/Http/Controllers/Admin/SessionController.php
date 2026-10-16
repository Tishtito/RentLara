<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginRequest;
use App\Http\Resources\AdminUserResource;
use App\Services\Auth\AdminAuthService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Cookie-session login for the React admin portal (Sanctum SPA auth).
 *
 * Architecture exception (docs/ARCHITECTURE.md): the session must be started before the response,
 * so these actions call AdminAuthService directly instead of dispatching an intent event.
 */
#[Group('Auth', weight: 0)]
class SessionController extends Controller
{
    public function __construct(private AdminAuthService $auth) {}

    /**
     * Log in (admin portal).
     *
     * For the React admin portal: call `GET /sanctum/csrf-cookie` first, then post with the
     * `X-XSRF-TOKEN` header from the `XSRF-TOKEN` cookie. Starts a cookie session; API clients use
     * `POST /api/admin/auth/token` instead. Limited to 6 attempts per minute.
     *
     * @unauthenticated
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $user = $this->auth->login($request->string('email'), $request->string('password'), $request->boolean('remember'));

        $request->session()->regenerate();

        return response()->json(['user' => new AdminUserResource($user)]);
    }

    /**
     * Log out (admin portal).
     *
     * Ends the admin cookie session. A tenant-portal login in the same browser is not affected.
     */
    public function destroy(Request $request): Response
    {
        $this->auth->logout();

        $request->session()->regenerate(true);
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
