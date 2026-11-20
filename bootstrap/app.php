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

        // Laravel Cloud terminates TLS at its load balancer; trust it so URLs, signed links and client IPs are right.
        $middleware->trustProxies(at: '*');

        // Signed out mid-session (logged out in another tab, or the session ended): say so instead of a silent bounce.
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->header('X-Inertia')) {
                Inertia::flash('toast', ['type' => 'warning', 'message' => 'You were signed out. Sign in again to continue.']);
            }

            return route('login');
        });

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

            // One of our own rate limits (generations, uploads, purchases): say so instead of a raw error page.
            if ($status === 429 && $request->header('X-Inertia')) {
                $wait = (int) ($response->headers->get('Retry-After') ?: 60);
                Inertia::flash('toast', ['type' => 'warning', 'message' => "Too many tries in a short time. Wait {$wait} seconds and try again."]);

                return back();
            }

            $friendly = [403, 404, 503, ...(app()->hasDebugModeEnabled() ? [] : [500])];
            // A background partial reload (the Studio's live status) is never swapped for an error page;
            // it gets the plain error, which the poll ignores and retries.
            $background = $request->hasHeader('X-Inertia-Partial-Component');
            if (in_array($status, $friendly, true) && ! $background && ! $request->is('webhooks/*') && ! $request->expectsJson()) {
                return Inertia::render('ErrorPage', ['status' => $status])->toResponse($request)->setStatusCode($status);
            }

            return $response;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'webhooks/*') || $request->expectsJson(),
        );
    })->create();
