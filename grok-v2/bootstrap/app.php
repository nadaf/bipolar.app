<?php

use App\Http\Controllers\Web\InstallerController;
use App\Http\Controllers\Web\LegacyImportController;
use App\Http\Middleware\EnsureMfa;
use App\Http\Middleware\EnsurePatient;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ResolveHost;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Support\FirstRunKey;
use App\Tenancy\Exceptions\MissingTenantContext;
use App\Tenancy\Exceptions\TenantMismatch;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

if (PHP_SAPI !== 'cli') {
    FirstRunKey::ensure(dirname(__DIR__));
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // instalador fora do grupo web (sem sessao/cookies criptografados)
            Route::group([], function () { // sem throttle: cache/sessao ainda nao existem antes da instalacao
                Route::get('/instalar', [InstallerController::class, 'form']);
                Route::post('/instalar', [InstallerController::class, 'run']);
                Route::get('/atualizar', [InstallerController::class, 'form']);
                Route::post('/atualizar', [InstallerController::class, 'run']);
                // dados da 0.5: so pelo botao, com o INSTALLER_TOKEN (nunca automatico)
                Route::get('/migrar-dados', [LegacyImportController::class, 'form']);
                Route::post('/migrar-dados', [LegacyImportController::class, 'run']);
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->web(prepend: [ResolveHost::class]);
        $middleware->api(prepend: [ResolveHost::class]);
        $middleware->alias(['tenant' => ResolveTenant::class, 'patient' => EnsurePatient::class, 'role' => EnsureRole::class, 'mfa' => EnsureMfa::class]);
        $middleware->redirectGuestsTo('/entrar');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Falha fechada: erro de tenancy vira 404 generico (e e logado).
        $exceptions->render(fn (MissingTenantContext|TenantMismatch $e) => abort(404));
        $exceptions->dontFlash(['password', 'password_confirmation', 'note']);
    })->create();
