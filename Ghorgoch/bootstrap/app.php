<?php

use App\Auth\Middleware\RequireCsrfToken;
use App\Users\Middleware\EnforceStaffAccess;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToGroup('api', EnforceStaffAccess::class);
        $middleware->group('auth-session', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            RequireCsrfToken::class,
            SubstituteBindings::class,
        ]);
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            RequireCsrfToken::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'token']);
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = match (true) {
                $exception instanceof ValidationException => 422,
                $exception instanceof AuthenticationException => 401,
                $exception instanceof TokenMismatchException => 419,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            $message = match ($status) {
                401 => $exception instanceof AuthenticationException ? $exception->getMessage() : 'Unauthenticated.',
                403 => 'This operation is forbidden.',
                404 => 'Route not found.',
                405 => 'This HTTP method is not allowed.',
                409 => 'The last active administrator must remain active.',
                419 => 'CSRF token mismatch. Fetch a new CSRF token.',
                422 => 'The given data was invalid.',
                429 => 'Too many attempts. Please try again later.',
                default => 'Unable to process this request.',
            };
            $body = ['message' => $message, 'data' => []];
            if ($exception instanceof ValidationException) {
                $body['errors'] = $exception->errors();
            }
            $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

            return response()->json($body, $status, $headers)->header('Cache-Control', 'no-store, private');
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
