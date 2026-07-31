<?php
use App\Http\Middleware\AuthenticateServiceToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(fn (Middleware $middleware) => $middleware->alias(['service' => AuthenticateServiceToken::class]))
    ->withExceptions(fn (Exceptions $exceptions) => null)->create();
