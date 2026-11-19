<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectUsersTo('/studio');

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Pixelforge can't send a CSRF token; its requests are verified by signature instead.
        $middleware->preventRequestForgery(except: ['webhooks/pixelforge']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Friendly pages instead of framework ones; a stale session sends you to sign in with an explanation.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            if ($status === 419) {
                Inertia::flash('toast', ['type' => 'warning', 'message' => 'Your session expired. Sign in again to continue.']);

                return $request->user() ? back() : redirect()->route('login');
            }

            $friendly = [403, 404, 503, ...(app()->hasDebugModeEnabled() ? [] : [500])];
            if (in_array($status, $friendly, true) && ! $request->is('webhooks/*') && ! $request->expectsJson()) {
                return Inertia::render('ErrorPage', ['status' => $status])->toResponse($request)->setStatusCode($status);
            }

            return $response;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'webhooks/*') || $request->expectsJson(),
        );
    })->create();
