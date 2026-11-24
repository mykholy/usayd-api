<?php

use App\Http\Middleware\AssignTraceId;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\RequireReasonMetadata;
use App\Support\Http\ProblemDetailsResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);
        $middleware->append(AssignTraceId::class);
        $middleware->alias([
            'idempotent' => EnforceIdempotency::class,
            'ability' => CheckForAnyAbility::class,
            'abilities' => CheckAbilities::class,
            'role' => EnsureRole::class,
            'require.reason' => RequireReasonMetadata::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ProblemDetailsResponse::fromThrowable($e, $request);
            }
        });
    })->create();
