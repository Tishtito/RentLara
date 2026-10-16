<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IssueTokenRequest;
use App\Http\Resources\AdminUserResource;
use App\Services\Auth\ApiTokenService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Architecture exception (docs/ARCHITECTURE.md): the token has to come back in the response,
 * so these actions call ApiTokenService directly instead of dispatching an intent event.
 */
#[Group('Auth', weight: 0)]
class AuthTokenController extends Controller
{
    public function __construct(private ApiTokenService $tokens) {}

    /**
     * Issue an API token.
     *
     * Log in with email and password and get a bearer token for the admin API. Send it as
     * `Authorization: Bearer <token>` (in these docs: paste it into the Bearer field). The token is
     * shown only once. Limited to 6 attempts per minute.
     *
     * @unauthenticated
     */
    public function store(IssueTokenRequest $request): JsonResponse
    {
        [$user, $token] = $this->tokens->issue(
            $request->string('email'),
            $request->string('password'),
            $request->input('device_name') ?: 'api',
        );

        return response()->json([
            /** @example 1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx */
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new AdminUserResource($user),
        ]);
    }

    /**
     * Current user.
     *
     * Who the token belongs to: role and landlord account (`null` for super admins).
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['user' => new AdminUserResource($request->user())]);
    }

    /**
     * Revoke the current token.
     *
     * Logs out the token this request was made with. Other tokens of the same user keep working.
     */
    public function destroy(Request $request): Response
    {
        $this->tokens->revoke($request->user());

        return response()->noContent();
    }
}
