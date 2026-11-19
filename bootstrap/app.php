<?php
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([App\Providers\AppServiceProvider::class])
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectUsersTo(fn (\Illuminate\Http\Request $request) => route($request->user()->homeRouteName()));
        $middleware->web(append: [SecurityHeaders::class]);
        $middleware->alias(['role' => EnsureRole::class, 'permission' => EnsurePermission::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {})->create();
