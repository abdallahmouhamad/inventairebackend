<?php

use App\Http\Middleware\EnsureMobileRole;
use App\Http\Middleware\EnsureWebRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API pure, pas de route "login" web : ne jamais tenter de generer une
        // redirection vers elle (sinon RouteNotFoundException au lieu d'un 401 JSON).
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'role.web' => EnsureWebRole::class,
            'role.mobile' => EnsureMobileRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API pure : jamais de redirection vers une route "login" web (qui n'existe
        // pas ici), toujours du JSON, peu importe les headers Accept envoyes.
        $exceptions->render(function (AuthenticationException $e, $request) {
            return response()->json(['errors' => ['Authentification requise.']], 401);
        });
    })->create();
