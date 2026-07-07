<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$basePath = dirname(__DIR__);

$app = Application::configure(basePath: $basePath)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();

// Mirrors the layout detection in public/index.php: shared hosts that only
// expose a single web root (e.g. InfinityFree) get public/'s contents
// flattened into the app root, so there's no public/ subfolder for
// public_path() (and therefore Vite's build/manifest.json lookup) to
// resolve against — point it at the app root instead in that case.
if (! is_dir($basePath.'/public')) {
    $app->usePublicPath($basePath);
}

return $app;
