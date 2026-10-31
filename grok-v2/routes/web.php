<?php

use App\Http\Controllers\Web\AccountController;
use App\Http\Controllers\Web\AlertSettingsController;
use App\Http\Controllers\Web\CareController;
use App\Http\Controllers\Web\ClinicController;
use App\Http\Controllers\Web\HistoryController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\PushController;
use App\Http\Controllers\Web\RegistrarController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\SessionController;
use App\Http\Controllers\Web\ShareController;
use Illuminate\Support\Facades\Route;

Route::get('/entrar', [SessionController::class, 'form'])->name('login');
Route::post('/entrar', [SessionController::class, 'login'])->middleware('throttle:auth');
Route::get('/criar-conta', [SessionController::class, 'signupForm'])->middleware('guest')->name('signup');
Route::post('/criar-conta', [SessionController::class, 'signup'])->middleware(['guest', 'throttle:auth']);
Route::post('/sair', [SessionController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('/', [HomeController::class, 'home'])->name('home');
    Route::post('/tema', [HomeController::class, 'theme'])->name('theme');
    Route::post('/desbloquear', [HomeController::class, 'unlock'])->middleware('throttle:auth')->name('stepup');
    Route::post('/bloquear', [HomeController::class, 'lock'])->name('stepup.lock');

    Route::get('/conta', [AccountController::class, 'show'])->name('account');
    Route::post('/conta', [AccountController::class, 'profile'])->name('account.profile');
    Route::post('/conta/pin', [AccountController::class, 'pin'])->middleware('throttle:auth')->name('account.pin');
    Route::post('/conta/email', [AccountController::class, 'email'])->middleware('throttle:auth')->name('account.email');
    Route::get('/conta/email/confirmar/{token}', [AccountController::class, 'confirmEmail'])->name('account.email.confirm');
    Route::post('/conta/excluir', [AccountController::class, 'destroy'])->middleware('throttle:auth')->name('account.delete');

    Route::get('/convite/{token}', [CareController::class, 'invite'])->name('invite');
    Route::post('/convite/{token}', [CareController::class, 'accept'])->middleware('throttle:auth')->name('invite.accept');
    Route::get('/cuidado', [CareController::class, 'index'])->name('care.index');
    Route::get('/cuidado/{patient}', [CareController::class, 'show'])->whereNumber('patient')->name('care.show');

    Route::middleware('role:admin')->group(function () {
        Route::get('/clinica', [ClinicController::class, 'index'])->name('clinic');
        Route::post('/clinica/profissionais', [ClinicController::class, 'add'])->name('clinic.add');
        Route::post('/clinica/profissionais/{user}/remover', [ClinicController::class, 'remove'])->whereNumber('user')->name('clinic.remove');
    });
});

Route::middleware(['auth', 'tenant', 'patient'])->group(function () {
    Route::redirect('/hoje', '/registrar');
    Route::get('/registrar', [RegistrarController::class, 'index'])->name('diary.today');
    Route::post('/registrar/humor/{card}', [RegistrarController::class, 'saveMood'])->name('diary.mood');
    Route::post('/registrar/bloco/{block}', [RegistrarController::class, 'saveBlock'])->name('diary.block');
    Route::get('/historico', [HistoryController::class, 'index'])->name('history');
    Route::get('/historico/{date}', [HistoryController::class, 'day'])->name('history.day');
    Route::get('/relatorios', [ReportController::class, 'index'])->name('reports');
    Route::get('/alertas', [AlertSettingsController::class, 'edit'])->name('alerts');
    Route::post('/alertas', [AlertSettingsController::class, 'update'])->name('alerts.save');
    Route::post('/alertas/lembretes', [AlertSettingsController::class, 'reminders'])->name('alerts.reminders');
    Route::post('/alertas/push', [PushController::class, 'subscribe'])->middleware('throttle:30,1')->name('push.subscribe');
    Route::post('/alertas/push/remover', [PushController::class, 'unsubscribe'])->middleware('throttle:30,1')->name('push.unsubscribe');
    Route::redirect('/ajustes/alertas', '/alertas');
    Route::get('/compartilhar', [ShareController::class, 'index'])->name('share');
    Route::post('/compartilhar', [ShareController::class, 'store'])->name('share.store');
    Route::get('/compartilhar/{id}', [ShareController::class, 'show'])->whereNumber('id')->name('share.show');
    Route::post('/compartilhar/{id}', [ShareController::class, 'update'])->whereNumber('id')->name('share.update');
    Route::post('/compartilhar/{id}/revogar', [ShareController::class, 'revoke'])->whereNumber('id')->name('share.revoke');
    Route::post('/conta/exportar', [AccountController::class, 'export'])->name('account.export');
});
