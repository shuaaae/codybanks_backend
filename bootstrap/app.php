<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php', // ✅ ADD THIS LINE
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\IsAdmin::class,
            'enable-sessions' => \App\Http\Middleware\EnableSessions::class,
            'cors' => \App\Http\Middleware\CustomCorsMiddleware::class,
        ]);
        
        // Apply CORS middleware globally to all requests
        $middleware->web(prepend: [
            \App\Http\Middleware\CustomCorsMiddleware::class,
        ]);
        
        $middleware->api(prepend: [
            \App\Http\Middleware\CustomCorsMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
