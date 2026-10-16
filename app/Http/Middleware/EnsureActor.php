<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps each portal's routes to its own kind of login. Put it after `auth`:
 *
 *   actor:user    admin routes   (a tenant's API token must not reach the admin API)
 *   actor:tenant  portal routes  (an admin's token or cookie session must not act as a tenant)
 *
 * Anyone else is treated as not logged in (401), so each portal shows its own login.
 */
class EnsureActor
{
    private const ACTORS = ['user' => User::class, 'tenant' => Tenant::class];

    public function handle(Request $request, Closure $next, string $actor): Response
    {
        $class = self::ACTORS[$actor] ?? throw new \InvalidArgumentException("Unknown actor [{$actor}].");

        if (! $request->user() instanceof $class) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
