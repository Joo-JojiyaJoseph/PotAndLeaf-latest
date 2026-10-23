<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // API-only, stateless token auth (Sanctum). No Inertia/Fortify/team
        // middleware. The company scope is applied per-route by ResolveApiCompany.

        // There is no "login" web route. Without this, an API call made without
        // an "Accept: application/json" header (e.g. a PDF link opened in a new
        // tab after the session expired) crashed with "Route [login] not
        // defined" (HTTP 500) instead of returning 401.
        $middleware->redirectGuestsTo(fn ($request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Every api/* response is JSON, whatever Accept header the caller sent.
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());

        // The whole app is a JSON API. Never redirect to a (non-existent) "login"
        // route — return proper JSON status codes so the SPA can react.
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Not found.'], 404);
            }
        });
    })->create();
