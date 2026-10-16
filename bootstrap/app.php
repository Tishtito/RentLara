<?php

use App\Http\Middleware\EnsureActor;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\SetCurrentAccount;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias(['account' => SetCurrentAccount::class, 'actor' => EnsureActor::class, 'super' => EnsureSuperAdmin::class]);

        // Route model binding queries account-scoped models, so the account must be set first.
        $middleware->prependToPriorityList(SubstituteBindings::class, SetCurrentAccount::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
